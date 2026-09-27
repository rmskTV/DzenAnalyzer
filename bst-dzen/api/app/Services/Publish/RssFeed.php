<?php

namespace App\Services\Publish;

use App\Models\Channel;
use App\Models\OwnPublication;
use Illuminate\Support\Carbon;

/**
 * RSS-фид для автопубликации в Дзене (подключается в студии канала).
 * Отдаёт queued/published публикации за последние N дней — Дзен дедуплицирует по guid.
 */
class RssFeed
{
    public function build(Channel $own, int $days = 7): string
    {
        $items = OwnPublication::where('own_channel_id', $own->id)
            ->whereIn('status', ['queued', 'published'])
            ->where('scheduled_at', '>=', Carbon::now()->subDays($days))
            ->orderByDesc('scheduled_at')
            ->get();

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">';
        $xml[] = '<channel>';
        $xml[] = '  <title>'.$this->esc($own->title).'</title>';
        $xml[] = '  <link>https://dzen.ru/'.$this->esc($own->dzen_key).'</link>';
        $xml[] = '  <description>Автопубликация канала '.$this->esc($own->title).'</description>';
        $xml[] = '  <language>ru</language>';

        foreach ($items as $item) {
            $xml[] = '  <item>';
            $xml[] = '    <title>'.$this->esc($item->title).'</title>';
            $xml[] = '    <guid isPermaLink="false">bst-dzen-'.$item->id.'</guid>';
            $xml[] = '    <pubDate>'.($item->scheduled_at ?? $item->created_at)->copy()->timezone('UTC')->toRfc2822String().'</pubDate>';
            if ($item->source_url) {
                $xml[] = '    <link>'.$this->esc($item->source_url).'</link>';
            }
            $xml[] = '    <content:encoded><![CDATA['.$this->cdataSafe($item->body).']]></content:encoded>';
            $xml[] = '  </item>';
        }

        $xml[] = '</channel>';
        $xml[] = '</rss>';

        return implode("\n", $xml);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function cdataSafe(string $html): string
    {
        return str_replace(']]>', ']]&gt;', $html);
    }
}
