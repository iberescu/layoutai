<?php

namespace App\Services;

use App\Models\ProductFeed;
use App\Models\ProductFeedItem;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class ProductFeedService
{
    public function connect(Workspace $workspace, array $data, ?UploadedFile $file = null): ProductFeed
    {
        $feed = ProductFeed::create([
            'workspace_id' => $workspace->id,
            'source'       => $data['source'],
            'url'          => $data['url'] ?? null,
            'status'       => 'syncing',
        ]);

        try {
            if ($data['source'] === 'csv' && $file) {
                $this->ingestCsv($feed, $file->getRealPath());
            } elseif (in_array($data['source'], ['xml', 'google_merchant'], true) && ! empty($data['url'])) {
                $this->ingestXml($feed, $data['url']);
            }
            $feed->update(['status' => 'synced', 'last_synced_at' => now()]);
        } catch (\Throwable $e) {
            $feed->update(['status' => 'error', 'settings' => ['error' => $e->getMessage()]]);
        }

        return $feed;
    }

    public function ingestCsv(ProductFeed $feed, string $path): int
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            return 0;
        }
        $headers = array_map('strtolower', (array) fgetcsv($fh));
        $count = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $assoc = array_combine($headers, $row) ?: [];
            ProductFeedItem::updateOrCreate(
                ['product_feed_id' => $feed->id, 'external_id' => (string) ($assoc['id'] ?? $assoc['sku'] ?? uniqid())],
                [
                    'title'        => $assoc['title']        ?? null,
                    'description'  => $assoc['description']  ?? null,
                    'image_url'    => $assoc['image_url']    ?? $assoc['image'] ?? null,
                    'product_url'  => $assoc['link']         ?? $assoc['url']   ?? null,
                    'price_cents'  => $this->parsePrice($assoc['price'] ?? '0'),
                    'currency'     => strtoupper(substr((string) ($assoc['currency'] ?? 'USD'), 0, 3)),
                    'availability' => $assoc['availability'] ?? null,
                    'raw'          => $assoc,
                ]
            );
            $count++;
        }
        fclose($fh);
        return $count;
    }

    public function ingestXml(ProductFeed $feed, string $url): int
    {
        $body = (string) Http::timeout(30)->get($url)->body();
        if ($body === '') {
            return 0;
        }
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (! $xml) {
            throw new \RuntimeException('Could not parse XML feed');
        }

        $items = [];
        if (isset($xml->channel->item)) {           // RSS / Google Merchant
            $items = $xml->channel->item;
        } elseif (isset($xml->entry)) {              // Atom
            $items = $xml->entry;
        } elseif (isset($xml->product)) {            // Custom flat
            $items = $xml->product;
        }

        $count = 0;
        foreach ($items as $item) {
            $g = $item->children('http://base.google.com/ns/1.0');
            $extId = (string) ($g->id ?? $item->id ?? $item->guid ?? uniqid());
            $price = (string) ($g->price ?? $item->price ?? '0');

            ProductFeedItem::updateOrCreate(
                ['product_feed_id' => $feed->id, 'external_id' => $extId],
                [
                    'title'        => (string) ($item->title       ?? ''),
                    'description'  => (string) ($item->description ?? ''),
                    'image_url'    => (string) ($g->image_link     ?? $item->image_link ?? ''),
                    'product_url'  => (string) ($item->link        ?? $g->link ?? ''),
                    'price_cents'  => $this->parsePrice($price),
                    'currency'     => 'USD',
                    'availability' => (string) ($g->availability   ?? ''),
                    'raw'          => json_decode(json_encode($item), true),
                ]
            );
            $count++;
        }
        return $count;
    }

    private function parsePrice(string $value): int
    {
        $value = preg_replace('/[^0-9.,]/', '', $value);
        $value = str_replace(',', '.', (string) $value);
        return (int) round(((float) $value) * 100);
    }
}
