<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ingestion\Validation\DocumentRules;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessIngestBatch;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\IngestRejection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class IngestBatchController extends Controller
{
    public const MAX_BODY_BYTES = 5 * 1024 * 1024; // 5 MB

    public const MAX_DOCUMENTS = 500;

    public function store(Request $request): JsonResponse
    {
        $rawContent = $request->getContent();

        if (strlen($rawContent) > self::MAX_BODY_BYTES) {
            return response()->json([
                'message' => 'حجم الحزمة يتجاوز الحد المسموح به (5 ميجابايت).',
            ], 413);
        }

        $isGzip = strtolower((string) $request->header('Content-Encoding')) === 'gzip';
        if ($isGzip) {
            $decompressionResult = $this->decompressGzip($rawContent, self::MAX_BODY_BYTES);
            if ($decompressionResult['status'] === 'oversized') {
                return response()->json([
                    'message' => 'حجم البيانات بعد فك الضغط يتجاوز الحد المسموح به (5 ميجابايت).',
                ], 413);
            }
            if ($decompressionResult['status'] === 'malformed') {
                return response()->json([
                    'message' => 'فشل فك ضغط البيانات بتنسيق gzip.',
                ], 422);
            }
            $jsonString = $decompressionResult['content'];
        } else {
            $jsonString = $rawContent;
        }

        $data = json_decode($jsonString, true);
        if (! is_array($data)) {
            return response()->json([
                'message' => 'بيانات JSON غير صالحة.',
            ], 422);
        }

        if (isset($data['documents']) && is_array($data['documents']) && count($data['documents']) > self::MAX_DOCUMENTS) {
            return response()->json([
                'message' => 'عدد المستندات في الحزمة يتجاوز الحد الأقصى (500 مستند).',
            ], 413);
        }

        $envelopeValidator = Validator::make($data, [
            'schema_version' => ['required', 'integer', 'in:1'],
            'batch_uuid' => ['required', 'string', 'uuid'],
            'branch_uuid' => ['required', 'string'],
            'pos_version' => ['required', 'string'],
            'sent_at' => ['required', 'string'],
            'seq_from' => ['required', 'integer'],
            'seq_to' => ['required', 'integer'],
            'documents' => ['present', 'array'],
        ]);

        if ($envelopeValidator->fails()) {
            return response()->json([
                'message' => 'فشل التحقق من صحة غلاف الحزمة.',
                'errors' => $envelopeValidator->errors(),
            ], 422);
        }

        $branch = $request->user('sanctum');
        if (! $branch instanceof Branch) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($data['branch_uuid'] !== $branch->uuid) {
            return response()->json([
                'message' => 'معرف الفرع لا يتطابق مع الفرع المصرح له.',
            ], 403);
        }

        $now = now();
        $ext = $isGzip ? 'json.gz' : 'json';
        $storagePath = sprintf(
            'ingest/%s/%s/%s/%s/%s.%s',
            $branch->organization_id,
            $branch->id,
            $now->format('Y'),
            $now->format('m'),
            $data['batch_uuid'],
            $ext
        );

        $batchToDispatch = null;
        /** @var list<string> $acceptedUuids */
        $acceptedUuids = [];
        /** @var list<int> $acceptedIndices */
        $acceptedIndices = [];

        try {
            $response = DB::transaction(function () use (
                $branch,
                $data,
                $rawContent,
                $storagePath,
                $now,
                &$batchToDispatch,
                &$acceptedUuids,
                &$acceptedIndices
            ): JsonResponse {
                // Branch lock: serialize concurrent batch receipt for this branch
                Branch::where('id', $branch->id)->lockForUpdate()->first();

                // Idempotency check: if batch exists, return stored result with 409
                $existing = IngestBatch::where('branch_id', $branch->id)
                    ->where('batch_uuid', $data['batch_uuid'])
                    ->first();

                if ($existing) {
                    $rawResult = $existing->getAttribute('result');
                    $existingResult = is_array($rawResult) ? $rawResult : [];

                    if ($existing->status !== 'processed') {
                        $batchToDispatch = $existing;
                        /** @var list<string> $acceptedUuids */
                        $acceptedUuids = isset($existingResult['accepted']) && is_array($existingResult['accepted'])
                            ? array_values($existingResult['accepted'])
                            : [];
                        /** @var list<int>|null $acceptedIndices */
                        $acceptedIndices = isset($existingResult['_accepted_indices']) && is_array($existingResult['_accepted_indices'])
                            ? array_values($existingResult['_accepted_indices'])
                            : null;
                    }

                    $publicResult = [
                        'batch_uuid' => $existing->batch_uuid,
                        'accepted' => isset($existingResult['accepted']) && is_array($existingResult['accepted']) ? array_values($existingResult['accepted']) : [],
                        'rejected' => isset($existingResult['rejected']) && is_array($existingResult['rejected']) ? array_values($existingResult['rejected']) : [],
                    ];

                    return response()->json($publicResult, 409);
                }

                $accepted = [];
                $acceptedIdx = [];
                $rejected = [];

                foreach ($data['documents'] as $index => $doc) {
                    if (! is_array($doc)) {
                        $rejected[] = [
                            'uuid' => null,
                            'type' => 'unknown',
                            'reason' => 'يجب أن يكون المستند كائناً.',
                        ];

                        continue;
                    }

                    $type = $doc['type'] ?? null;
                    if (! is_string($type) || ! in_array($type, DocumentRules::TYPES, true)) {
                        $commonValidator = Validator::make($doc, DocumentRules::common());
                        $reason = $commonValidator->fails()
                            ? $commonValidator->errors()->first()
                            : 'نوع المستند غير مدعوم.';

                        $rejected[] = [
                            'uuid' => isset($doc['uuid']) && is_string($doc['uuid']) ? $doc['uuid'] : null,
                            'type' => is_string($type) ? $type : 'unknown',
                            'reason' => $reason,
                        ];

                        continue;
                    }

                    try {
                        $rules = DocumentRules::for($type);
                        $docValidator = Validator::make($doc, $rules);

                        if ($docValidator->fails()) {
                            $rejected[] = [
                                'uuid' => isset($doc['uuid']) && is_string($doc['uuid']) ? $doc['uuid'] : null,
                                'type' => $type,
                                'reason' => $docValidator->errors()->first(),
                            ];

                            continue;
                        }

                        $accepted[] = (string) $doc['uuid'];
                        $acceptedIdx[] = (int) $index;
                    } catch (Throwable $e) {
                        $rejected[] = [
                            'uuid' => isset($doc['uuid']) && is_string($doc['uuid']) ? $doc['uuid'] : null,
                            'type' => $type,
                            'reason' => $e->getMessage(),
                        ];
                    }
                }

                // Storage write: do not acknowledge as accepted on storage failure
                $stored = false;
                try {
                    $stored = Storage::disk('local')->put($storagePath, $rawContent);
                } catch (Throwable) {
                    $stored = false;
                }

                if (! $stored) {
                    throw new RuntimeException('فشل حفظ بيانات الحزمة في وحدة التخزين.');
                }

                $publicResult = [
                    'batch_uuid' => $data['batch_uuid'],
                    'accepted' => $accepted,
                    'rejected' => $rejected,
                ];

                $storedResult = array_merge($publicResult, [
                    '_accepted_indices' => $acceptedIdx,
                ]);

                $batch = IngestBatch::create([
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'batch_uuid' => $data['batch_uuid'],
                    'schema_version' => (int) $data['schema_version'],
                    'pos_version' => (string) $data['pos_version'],
                    'seq_from' => (int) $data['seq_from'],
                    'seq_to' => (int) $data['seq_to'],
                    'docs_count' => count($data['documents']),
                    'accepted_count' => count($accepted),
                    'rejected_count' => count($rejected),
                    'status' => 'received',
                    'result' => $storedResult,
                    'storage_path' => $storagePath,
                    'received_at' => $now,
                ]);

                foreach ($rejected as $rej) {
                    IngestRejection::create([
                        'organization_id' => $branch->organization_id,
                        'branch_id' => $branch->id,
                        'ingest_batch_id' => $batch->id,
                        'doc_type' => substr($rej['type'], 0, 32),
                        'doc_uuid' => $rej['uuid'] !== null ? substr($rej['uuid'], 0, 36) : null,
                        'reason' => $rej['reason'],
                        'pos_version' => (string) ($data['pos_version'] ?? $branch->pos_version),
                    ]);
                }

                $branch->update([
                    'last_seen_at' => $now,
                    'last_batch_at' => $now,
                ]);

                $batchToDispatch = $batch;
                $acceptedUuids = $accepted;
                $acceptedIndices = $acceptedIdx;

                return response()->json($publicResult, 202);
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'حدث خطأ أثناء حفظ الحزمة.',
            ], 500);
        }

        // Dispatch after commit
        if ($batchToDispatch !== null) {
            try {
                ProcessIngestBatch::dispatch($batchToDispatch->id, $acceptedUuids, $branch->id, $acceptedIndices);
            } catch (Throwable $e) {
                report($e);

                return response()->json([
                    'message' => 'حدث خطأ أثناء جدولة معالجة الحزمة.',
                ], 500);
            }
        }

        return $response;
    }

    /**
     * Decompress gzip content with a bounded max length (max + 1 sentinel).
     *
     * @return array{status: 'ok', content: string}|array{status: 'oversized'|'malformed'}
     */
    protected function decompressGzip(string $data, int $maxBytes): array
    {
        if (strlen($data) < 18 || substr($data, 0, 2) !== "\x1f\x8b") {
            return ['status' => 'malformed'];
        }

        $fp = fopen('php://temp', 'r+');
        if (! $fp) {
            return ['status' => 'malformed'];
        }
        fwrite($fp, $data);
        rewind($fp);

        $hadError = false;
        set_error_handler(function () use (&$hadError) {
            $hadError = true;

            return true;
        });

        try {
            $filter = stream_filter_append($fp, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 15 + 16]);
            if (! $filter) {
                return ['status' => 'malformed'];
            }

            $out = '';
            $sentinel = $maxBytes + 1;
            $chunkSize = 8192;

            while (! feof($fp)) {
                $toRead = min($chunkSize, $sentinel - strlen($out));
                if ($toRead <= 0) {
                    break;
                }
                $chunk = fread($fp, $toRead);
                if ($hadError || $chunk === false) {
                    return ['status' => 'malformed'];
                }
                if ($chunk === '') {
                    break;
                }
                $out .= $chunk;
                if (strlen($out) > $maxBytes) {
                    return ['status' => 'oversized'];
                }
            }
        } finally {
            restore_error_handler();
            fclose($fp);
        }

        return ['status' => 'ok', 'content' => $out];
    }
}
