<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\Publish\RssFeed;
use Illuminate\Http\Response;

class FeedController extends Controller
{
    /** Публичный RSS для подключения в студии Дзена: /feed/{dzen_key}.xml */
    public function __invoke(string $key, RssFeed $feed): Response
    {
        $own = Channel::own()
            ->where(function ($q) use ($key) {
                $q->where('dzen_key', $key)->orWhereRaw("REPLACE(dzen_key, '.', '_') = ?", [$key]);
            })
            ->firstOrFail();

        return response($feed->build($own), 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
