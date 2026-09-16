<?php

namespace App\Core\Tenancy;

use Closure;

/**
 * Which shop the current request, job or command is working for.
 *
 * Bound as a scoped instance, so Octane and queue workers get a fresh one per request and
 * per job: one shop's context can never leak into the next unit of work.
 *
 * "Unscoped" is the explicit, deliberate opposite: the operator panel and system jobs that
 * really do look across shops enter it on purpose. Nothing is unscoped by accident.
 */
final class TenantContext
{
    private ?string $shopId = null;

    private int $unscopedDepth = 0;

    public function id(): ?string
    {
        return $this->shopId;
    }

    public function has(): bool
    {
        return $this->shopId !== null;
    }

    /** @throws MissingTenantContext */
    public function require(): string
    {
        return $this->shopId ?? throw MissingTenantContext::create();
    }

    public function set(string $shopId): void
    {
        $this->shopId = $shopId;
    }

    public function clear(): void
    {
        $this->shopId = null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $shopId, Closure $callback): mixed
    {
        $previous = $this->shopId;
        $this->shopId = $shopId;

        try {
            return $callback();
        } finally {
            $this->shopId = $previous;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runUnscoped(Closure $callback): mixed
    {
        $this->unscopedDepth++;

        try {
            return $callback();
        } finally {
            $this->unscopedDepth--;
        }
    }

    /**
     * For middleware that makes a whole request cross-shop (the operator panel). The scoped
     * binding discards this instance when the request ends, so there is nothing to undo.
     */
    public function enterUnscoped(): void
    {
        $this->unscopedDepth++;
    }

    public function isUnscoped(): bool
    {
        return $this->unscopedDepth > 0;
    }
}
