<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
class SupportMigrationBlockedMail extends Mailable
{
    public $notice;
    public function __construct(array $notice) { $this->notice = $notice; }
    public function build()
    {
        return $this->subject('Migración pendiente de soporte - ticket #' . $this->notice['ticket_id'])
            ->text('emails.commerciocity.support-migration-blocked');
    }
}
