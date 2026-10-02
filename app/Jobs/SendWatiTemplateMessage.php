<?php

namespace App\Jobs;

use App\Services\Wati\WatiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one WATI template message (external I/O only). Never used for OTPs
 * (a queue payload would store the plaintext code). WatiClient never throws.
 */
class SendWatiTemplateMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** @param  string  $templateKey  key under config('portal.wati.templates') */
    public function __construct(public string $phone, public string $templateKey, public array $params = []) {}

    public function handle(WatiClient $wati): void
    {
        $wati->sendTemplate($this->phone, (string) config('portal.wati.templates.'.$this->templateKey), $this->params);
    }
}
