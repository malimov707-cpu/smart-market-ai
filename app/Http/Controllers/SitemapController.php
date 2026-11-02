<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/** Lightweight sitemap and robots for public SEO pages. */
class SitemapController extends Controller
{
    private const POPULAR = [
        'olma', 'kartoshka', 'pomidor', 'piyoz', 'sut', 'guruch', 'tuxum', 'non',
        'go‘sht', 'banan', 'sabzi', 'yog', 'sement', 'gipsokarton', 'laminat', 'germetik',
    ];

    public function sitemap(): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $urls = [[$base.'/market', 'daily', '0.9'], [$base.'/market/products', 'daily', '0.8']];
        foreach (self::POPULAR as $query) {
            $urls[] = [$base.'/market/product/'.str_replace(' ', '-', $query), 'daily', '0.7'];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', array_map(fn (array $url): string => '<url><loc>'.htmlspecialchars($url[0]).'</loc><changefreq>'.$url[1].'</changefreq><priority>'.$url[2].'</priority></url>', $urls))
            .'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $base = rtrim((string) config('app.url'), '/');

        return response("User-agent: *\nAllow: /market\nAllow: /market/products\nAllow: /market/product/\nDisallow: /account\nDisallow: /dashboard\nDisallow: /admin\nDisallow: /notifications\nDisallow: /ai\n\nSitemap: {$base}/sitemap.xml", 200, ['Content-Type' => 'text/plain']);
    }
}
