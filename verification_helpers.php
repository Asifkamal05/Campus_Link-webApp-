<?php

function isVerifiedUser($value): bool
{
    return (int) $value === 1;
}

function renderVerifiedName(string $name, bool $isVerified): string
{
    $safeName = htmlspecialchars($name);
    if (!$isVerified) {
        return $safeName;
    }

    return $safeName . ' <span class="verified-badge" title="Verified account" aria-label="Verified account">&#10004;</span>';
}

function renderVerificationStatus(bool $isVerified): string
{
    return $isVerified ? 'Verified' : 'Not Verified';
}
