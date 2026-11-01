<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/** Immutable snapshot of one cPanel account's workers and the flags that gate them. */
final class Account
{
    /**
     * @param array<string, Worker> $workers keyed by name, insertion order preserved
     * @param Identity|null $identity the cPanel account the workers belong to; null for data stored
     *                                before identities were recorded
     */
    public function __construct(
        public readonly string $user,
        public readonly array $workers = [],
        public readonly bool $suspended = false,
        public readonly bool $featureOff = false,
        public readonly ?Identity $identity = null,
    ) {
    }

    /** @param string|null $replacing previous name when this is a rename */
    public function withWorker(Worker $w, ?string $replacing = null): self
    {
        $workers = $this->workers;
        if ($replacing !== null && $replacing !== $w->name && isset($workers[$replacing])) {
            // Rebuild to keep the renamed worker in its original position.
            $rebuilt = [];
            foreach ($workers as $k => $v) {
                $rebuilt[$k === $replacing ? $w->name : $k] = $k === $replacing ? $w : $v;
            }
            $workers = $rebuilt;
        }
        $workers[$w->name] = $w;

        return new self($this->user, $workers, $this->suspended, $this->featureOff, $this->identity);
    }

    public function withoutWorker(string $name): self
    {
        $workers = $this->workers;
        unset($workers[$name]);

        return new self($this->user, $workers, $this->suspended, $this->featureOff, $this->identity);
    }

    public function withFlags(bool $suspended, bool $featureOff): self
    {
        return new self($this->user, $this->workers, $suspended, $featureOff, $this->identity);
    }

    public function withIdentity(Identity $identity): self
    {
        return new self($this->user, $this->workers, $this->suspended, $this->featureOff, $identity);
    }

    /**
     * Whether the workers belong to the cPanel account that now has the name. Data stored before
     * identities were recorded belongs to whoever has the name; an account whose identity cannot
     * be read is never trusted with recorded data.
     */
    public function ownedBy(?Identity $current): bool
    {
        return $this->identity === null || $this->identity->equals($current);
    }

    public function runnable(Worker $w): bool
    {
        return $w->desired === 'running' && !$this->held($w);
    }

    /** Stopped for a reason of root's, which the client cannot lift (unlike their own stop). */
    public function held(Worker $w): bool
    {
        return $w->adminStopped || $this->suspended || $this->featureOff;
    }
}
