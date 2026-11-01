<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use InvalidArgumentException;

/**
 * Which cPanel account a username stands for. The name alone is not enough: a later account can
 * take the name of a removed one, and a uid can be given again, but not with the same STARTDATE.
 * cPanel keeps both on a username change (checked on 11.138).
 */
final class Identity
{
    /** @param string $startdate the cpuser STARTDATE, '' when the file has none */
    public function __construct(public readonly int $uid, public readonly string $startdate)
    {
    }

    /**
     * @param array<string, mixed> $a
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $a): self
    {
        $uid = $a['uid'] ?? null;
        $startdate = $a['startdate'] ?? null;
        if (!is_int($uid) || $uid < 0 || !is_string($startdate)) {
            throw new InvalidArgumentException('Invalid account identity.');
        }

        return new self($uid, $startdate);
    }

    /** @return array{uid: int, startdate: string} */
    public function toArray(): array
    {
        return ['uid' => $this->uid, 'startdate' => $this->startdate];
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->uid === $this->uid && $other->startdate === $this->startdate;
    }

    public function __toString(): string
    {
        return "uid {$this->uid}, STARTDATE {$this->startdate}";
    }
}
