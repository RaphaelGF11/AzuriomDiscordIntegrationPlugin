<?php

namespace Azuriom\Plugin\DiscordIntegration\Support\Concerns;

use Azuriom\Models\User;
use Azuriom\Plugin\Shop\Models\PaymentItem;
use Azuriom\Plugin\Shop\Models\Subscription;

/**
 * Shared by RoleSyncEvaluator and CommandConditionEvaluator - both need
 * to check "does this user own this shop package" as one of their
 * condition types.
 */
trait ChecksShopPackageOwnership
{
    /**
     * Whether the user currently owns an active subscription to, or a
     * non-expired one-time purchase of, the given shop package. Always false
     * if the (optional) shop plugin isn't installed.
     */
    protected function ownsPackage(User $user, int $packageId): bool
    {
        if (! class_exists(Subscription::class)) {
            return false;
        }

        $hasActiveSubscription = Subscription::where('user_id', $user->id)
            ->where('package_id', $packageId)
            ->scopes('active')
            ->exists();

        if ($hasActiveSubscription) {
            return true;
        }

        return PaymentItem::where('buyable_type', 'shop.packages')
            ->where('buyable_id', $packageId)
            ->whereHas('payment', fn ($query) => $query->where('user_id', $user->id)->scopes('completed'))
            ->scopes('excludeExpired')
            ->exists();
    }
}
