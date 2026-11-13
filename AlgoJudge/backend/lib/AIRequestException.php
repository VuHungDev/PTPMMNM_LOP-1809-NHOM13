<?php
/** Sanitized provider failure; never includes credentials, request text or a raw provider body. */
final class AIRequestException extends RuntimeException
{
    private string $reason;
    private int $status;

    public function __construct(string $message, string $reason, int $status)
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->status = $status;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
