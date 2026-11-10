<?php

namespace App\Models;

use App\Enums\MailboxMessageDirection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mailbox_identity_id',
    'provider_message_id',
    'direction',
    'from_address',
    'to_address',
    'subject',
    'body_text',
    'body_html',
    'received_at',
    'sent_at',
    'in_reply_to_message_id',
])]
class MailboxMessage extends Model
{
    /**
     * @return BelongsTo<MailboxIdentity, $this>
     */
    public function mailboxIdentity(): BelongsTo
    {
        return $this->belongsTo(MailboxIdentity::class);
    }

    /**
     * @return BelongsTo<MailboxMessage, $this>
     */
    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'in_reply_to_message_id');
    }

    /**
     * @return array{
     *     id: int,
     *     direction: string,
     *     fromAddress: string,
     *     toAddress: string,
     *     subject: string,
     *     bodyText: string|null,
     *     bodyHtml: string|null,
     *     receivedAt: string|null,
     *     sentAt: string|null,
     *     inReplyToMessageId: int|null,
     *     createdAt: string|null
     * }
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction->value,
            'fromAddress' => $this->from_address,
            'toAddress' => $this->to_address,
            'subject' => $this->subject,
            'bodyText' => $this->body_text,
            'bodyHtml' => $this->body_html,
            'receivedAt' => $this->received_at?->toISOString(),
            'sentAt' => $this->sent_at?->toISOString(),
            'inReplyToMessageId' => $this->in_reply_to_message_id,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MailboxMessageDirection::class,
            'received_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
