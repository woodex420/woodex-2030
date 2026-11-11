<?php

declare(strict_types=1);

namespace App\Domain\System\Alerts;

/**
 * The alert catalogue (AUT-003, TGM-002, docs/02 §8): every event the OS raises an alert for today,
 * with the routing it ships with. The defaults are not rules — they are what happens when no rule
 * exists, read straight from the code that sends today. A rule overrides recipients, channels,
 * quiet hours and priority for one event; deleting it returns the event to the default.
 */
final class AlertCatalogue
{
    /**
     * @return list<array{key: string, label: string, description: string, defaults: string}>
     */
    public static function events(): array
    {
        return [
            ['key' => 'lead.new', 'label' => 'New lead', 'description' => 'A new enquiry arrives (or a returning client writes again).', 'defaults' => 'Assignee + managers, by the lead-assignment rules · Telegram if linked, else email · in-app'],
            ['key' => 'lead.sla_breach', 'label' => 'Lead SLA breach', 'description' => 'A lead passed its first-response time; the second breach escalates.', 'defaults' => 'Owner + managers · Telegram · in-app'],
            ['key' => 'task.due', 'label' => 'Task due', 'description' => 'A task lands due today or overdue.', 'defaults' => 'The assignee · Telegram · in-app'],
            ['key' => 'visit.reminder', 'label' => 'Site visit reminder', 'description' => 'A visit is tomorrow, or in two hours.', 'defaults' => 'The visit owner · Telegram · in-app'],
            ['key' => 'quote.viewed', 'label' => 'Quote viewed', 'description' => 'First open of a quote link, or several views in a day (a good moment to call).', 'defaults' => 'Quote watchers · Telegram · in-app'],
            ['key' => 'quote.chase', 'label' => 'Quote chase', 'description' => 'A sent quote is ageing unviewed or nearing expiry.', 'defaults' => 'Quote watchers · Telegram · in-app'],
            ['key' => 'quote.accepted', 'label' => 'Quote accepted', 'description' => 'A client accepts a quote (or the bot collects the acceptance).', 'defaults' => 'Quote watchers · Telegram · in-app'],
            ['key' => 'quote.approval', 'label' => 'Quote approval', 'description' => 'A discount or low-margin quote needs approval — or the decision lands.', 'defaults' => 'Approvers · Telegram (inline buttons) · in-app'],
            ['key' => 'quote.changes_requested', 'label' => 'Quote changes requested', 'description' => 'A client asks for changes to a quote.', 'defaults' => 'Quote watchers · Telegram · in-app'],
            ['key' => 'payment.verified', 'label' => 'Payment verified', 'description' => 'A payment proof is verified and the receipt is on its way.', 'defaults' => 'Everyone with invoices.view · Telegram · in-app'],
            ['key' => 'payment.rejected', 'label' => 'Payment proof rejected', 'description' => 'A payment proof does not match the invoice.', 'defaults' => 'Everyone with invoices.view · Telegram · in-app'],
            ['key' => 'project.stage', 'label' => 'Project stage changed', 'description' => 'A project moves to its next stage.', 'defaults' => 'Everyone with projects.view · Telegram · in-app'],
            ['key' => 'project.update', 'label' => 'Project update shared', 'description' => 'A progress update or photographs go out to the client.', 'defaults' => 'Everyone with projects.view · Telegram · in-app'],
            ['key' => 'crm.mentioned', 'label' => 'Mentioned on a lead', 'description' => 'Someone @mentions a teammate on a lead timeline.', 'defaults' => 'The mentioned teammate · Telegram · in-app'],
            ['key' => 'conversation.mentioned', 'label' => 'Mentioned in a chat', 'description' => 'Someone @mentions a teammate inside a conversation.', 'defaults' => 'The mentioned teammate · Telegram · in-app'],
            ['key' => 'conversation.sla', 'label' => 'Chat waiting too long', 'description' => 'A WhatsApp/chat conversation waits for a human past its SLA; escalation follows.', 'defaults' => 'Chat owners + managers · Telegram · in-app'],
            ['key' => 'chat.handoff', 'label' => 'Bot hands a chat over', 'description' => 'The assistant passes a conversation to a human.', 'defaults' => 'Chat owners + managers · Telegram · in-app'],
            ['key' => 'system.agent_report', 'label' => 'Agent reports', 'description' => 'The weekly SEO and social reports.', 'defaults' => 'Owners · Telegram · in-app'],
            ['key' => 'system.change_request', 'label' => 'Change request answered', 'description' => 'A request raised from Telegram /idea or /issue gets its reply.', 'defaults' => 'The requester · Telegram · in-app'],
            ['key' => 'system.whatsapp_quality', 'label' => 'WhatsApp quality change', 'description' => 'The WhatsApp quality rating moves.', 'defaults' => 'Integrations managers · Telegram · in-app'],
            ['key' => 'system.whatsapp_usage', 'label' => 'WhatsApp usage', 'description' => 'Monthly WhatsApp spend against the budget.', 'defaults' => 'Integrations managers · Telegram · in-app'],
            ['key' => 'system.monthly_report', 'label' => 'Monthly report', 'description' => 'The month-end numbers report.', 'defaults' => 'Owners · Telegram · in-app'],
        ];
    }

    /**
     * @return array{key: string, label: string, description: string, defaults: string}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::events() as $event) {
            if ($event['key'] === $key) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $event): string => $event['key'], self::events());
    }
}
