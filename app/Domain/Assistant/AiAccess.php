<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Enums\OrganizationPlan;
use App\Models\User;
use Throwable;

final class AiAccess
{
    public const CODE_NO_ORGANIZATION = 'no_organization';

    public const CODE_NO_PERMISSION = 'no_permission';

    public const CODE_AI_DISABLED = 'ai_disabled';

    public const CODE_PLAN_NOT_ELIGIBLE = 'plan_not_eligible';

    /**
     * Determine whether the given user may access the AI assistant.
     */
    public function allows(User $user): bool
    {
        return $this->denialReason($user) === null;
    }

    /**
     * Return the stable denial code if the user cannot access the assistant, or null if allowed.
     */
    public function denialReason(User $user): ?string
    {
        $organization = $user->organization;

        if ($organization === null) {
            return self::CODE_NO_ORGANIZATION;
        }

        try {
            $hasPermission = $user->can('ai.view');
        } catch (Throwable) {
            $hasPermission = false;
        }

        if (! $hasPermission) {
            return self::CODE_NO_PERMISSION;
        }

        if (! (bool) $organization->ai_enabled) {
            return self::CODE_AI_DISABLED;
        }

        $plan = $organization->getAttribute('plan');
        $isPro = $plan === OrganizationPlan::PRO || $plan === OrganizationPlan::PRO->value;

        if (! $isPro) {
            return self::CODE_PLAN_NOT_ELIGIBLE;
        }

        return null;
    }

    /**
     * Return a polite Arabic message explaining the denial reason.
     */
    public function messageAr(string $code): string
    {
        return match ($code) {
            self::CODE_NO_ORGANIZATION => 'المستخدم غير مرتبط بأي مؤسسة حالياً.',
            self::CODE_NO_PERMISSION => 'ليس لديك صلاحية استخدام المساعد الذكي. يرجى التواصل مع مدير الحساب.',
            self::CODE_AI_DISABLED => 'خدمة المساعد الذكي غير مفعلة لمؤسستك حالياً.',
            self::CODE_PLAN_NOT_ELIGIBLE => 'المساعد الذكي متاح فقط في باقة الاحترافي (Pro). يرجى ترقية باقة المؤسسة للاستفادة من هذه الميزة.',
            default => 'عذراً، لا يمكن الوصول إلى المساعد الذكي في الوقت الحالي.',
        };
    }
}
