<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NewsUpdateCommand extends Command
{
    protected $signature = 'news:update';
    protected $description = 'Fetch US-Iran conflict news and send to Telegram';

    private const SEEN_URLS_FILE = 'seen_urls.json';
    private const CACHE_TTL_HOURS = 168;

    public function handle(): int
    {
        $this->info('Fetching news...');

        $query = '("US Iran" OR "Iran war" OR "Iran strike" OR "Gulf conflict" OR "Tehran Washington") AND ("military" OR "attack" OR "strike" OR "conflict" OR "tension" OR "nuclear")';

        $response = Http::retry(3, 2000)->timeout(30)->get('https://newsapi.org/v2/everything', [
            'q' => $query,
            'apiKey' => config('services.news_api.token'),
            'language' => 'en',
            'sortBy' => 'publishedAt',
            'pageSize' => 10,
            'from' => now()->subDay()->toDateString(),
        ]);

        if ($response->failed()) {
            $this->error('Failed to fetch news: ' . $response->status());
            Log::error('NewsAPI request failed', ['status' => $response->status()]);
            return Command::FAILURE;
        }

        $data = $response->json();
        $articles = $data['articles'] ?? [];

        if (empty($articles)) {
            $this->warn('No articles found');
            return Command::SUCCESS;
        }

        $seenUrls = $this->loadSeenUrls();
        $newArticles = [];

        foreach ($articles as $article) {
            $url = $article['url'] ?? null;
            if ($url && !in_array($url, $seenUrls)) {
                $newArticles[] = $article;
                $seenUrls[$url] = now()->timestamp;
            }
            if (count($newArticles) >= 5) {
                break;
            }
        }

        if (empty($newArticles)) {
            $this->info('All articles already sent previously.');
            return Command::SUCCESS;
        }

        $message = $this->formatMessage($newArticles);
        $this->sendToTelegram($message);
        $this->saveSeenUrls($seenUrls);

        $this->info('News update sent to Telegram!');
        return Command::SUCCESS;
    }

    private function loadSeenUrls(): array
    {
        $path = storage_path('app/' . self::SEEN_URLS_FILE);
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true) ?? [];
        $cutoff = now()->subHours(self::CACHE_TTL_HOURS)->timestamp;
        return array_filter($data, fn($timestamp) => $timestamp > $cutoff);
    }

    private function saveSeenUrls(array $urls): void
    {
        $path = storage_path('app/' . self::SEEN_URLS_FILE);
        file_put_contents($path, json_encode($urls));
    }

    private function formatMessage(array $articles): string
    {
        $now = now()->format('M j, Y | H:i T');
        $message = "🚨 US-IRAN CONFLICT UPDATE\n\n";
        $message .= "📰 TOP HEADLINES\n\n";

        foreach (array_values($articles) as $index => $article) {
            $num = $index + 1;
            $title = $this->escapeMarkdown($article['title'] ?? 'No title');
            $url = $article['url'] ?? '';
            $source = $article['source']['name'] ?? 'Unknown';
            $publishedAt = $article['publishedAt'] ?? null;
            $description = $article['description'] ?? $article['content'] ?? '';
            $timeAgo = $publishedAt ? \Carbon\Carbon::parse($publishedAt)->diffForHumans() : '';

            $message .= "{$num}️⃣ [{$title}]({$url})\n";
            $message .= "   {$source}";
            if ($timeAgo) {
                $message .= " • {$timeAgo}";
            }
            $message .= "\n";
            if ($description) {
                $excerpt = mb_substr(strip_tags($description), 0, 100);
                if (mb_strlen(strip_tags($description)) > 100) {
                    $excerpt .= '...';
                }
                $excerpt = $this->escapeMarkdown($excerpt);
                $message .= "   {$excerpt}\n";
            }
            $message .= "\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "⏰ {$now}";

        return $message;
    }

    private function escapeMarkdown(string $text): string
    {
        $text = strip_tags($text);
        $specialChars = ['*', '_', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }

    private function sendToTelegram(string $message): void
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        $response = Http::retry(3, 2000)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        if ($response->failed()) {
            $this->error('Failed to send Telegram message');
            Log::error('Telegram send failed', ['response' => $response->json()]);
        }
    }
}
