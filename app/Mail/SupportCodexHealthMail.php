<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class SupportCodexHealthMail extends Mailable
{
    public $kind;
    public $health;

    public function __construct(string $kind, array $health)
    {
        $this->kind = $kind;
        $this->health = $health;
    }

    public function build()
    {
        $subject = $this->kind === 'recovery' ? 'El monitor de soporte volvió a funcionar' : 'El monitor de soporte no está funcionando';
        if ($this->kind === 'test') {
            $subject = 'Prueba de alerta del monitor de soporte';
        }
        return $this->subject($subject)->text('emails.commerciocity.support-monitor-health');
    }
}
