<?php

namespace App\Domain\Tenancy;

use App\Models\Branch;
use App\Models\BranchPairingCode;
use App\Models\User;

class PairingCodeService
{
    /**
     * Alphabet without ambiguous characters (no 0, O, 1, I).
     */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function generate(Branch $branch, ?User $by = null): string
    {
        // Invalidate previous unused codes of that branch
        BranchPairingCode::where('branch_id', $branch->id)
            ->whereNull('used_at')
            ->update(['expires_at' => now()->subSecond()]);

        $maxIndex = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        BranchPairingCode::create([
            'branch_id' => $branch->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(15),
            'created_by' => $by?->id,
        ]);

        return $code;
    }

    public static function redeem(string $code): ?Branch
    {
        $cleanCode = strtoupper(trim($code));
        $codeHash = hash('sha256', $cleanCode);

        $pairingCode = BranchPairingCode::where('code_hash', $codeHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $pairingCode) {
            return null;
        }

        $pairingCode->update(['used_at' => now()]);

        return $pairingCode->branch;
    }
}
