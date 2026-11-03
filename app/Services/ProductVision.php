<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductVision
{
    public function __construct(private OpenRouterAssistant $assistant) {}

    public function identify(UploadedFile $file): array
    {
        $image = imagecreatefromstring($file->getContent());
        if ($image === false) {
            throw new AssistantFailure('Rasmni o‘qib bo‘lmadi.', 422);
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 1024 / max($width, $height));
        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagedestroy($image);
        if ($scaled === false) {
            throw new AssistantFailure('Rasmni tayyorlab bo‘lmadi.', 422);
        }
        ob_start();
        imagejpeg($scaled, null, 78);
        $jpeg = ob_get_clean();
        imagedestroy($scaled);
        $result = $this->assistant->answer('Suratdagi buyumni ko‘rinishidan aniqlang; yorliq bo‘lishi shart emas. Mahsulotni faqat oziq-ovqat deb taxmin qilmang: stol, stul, ichimlik, asbob, maishiy texnika yoki boshqa jihoz bo‘lishi mumkin. Avval buyumning shakli va vazifasiga qarang; yorliqdagi matndan faqat ko‘rinib turgan brend yoki modelni oling. Mahsulot nomini tushunarli, tabiiy o‘zbek lotin yozuvida qaytaring; ruscha tovushlarni o‘zbekcha harflarga ko‘chirib, g‘alati nom tuzmang. Faqat ko‘rinib turgan ma’lumotni kiriting, noaniq bo‘lsa uncertain=true deb belgilang. Narx, yashirin matn yoki ko‘rinmayotgan shtrix-kod ma’lumotini taxmin qilmang.', [], [], 'data:image/jpeg;base64,'.base64_encode($jpeg));
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($result['answer']));
        try {
            $data = json_decode($text, true, 12, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AssistantFailure('AI mahsulotni aniq formatda taniy olmadi. Boshqa rakursdan suratga oling.');
        }
        if (isset($data['items']) && is_array($data['items'])) {
            $items = [];
            foreach ($data['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $normalized = ['name' => $item['name'] ?? null, 'brand' => $item['brand'] ?? null, 'package' => $item['package'] ?? null,
                    'uncertain' => (bool) ($item['uncertain'] ?? false), 'search_terms' => []];
                if (! is_string($normalized['name']) || trim($normalized['name']) === '') {
                    continue;
                }
                $searchTerms = $item['search_terms'] ?? [];
                if (! is_array($searchTerms)) {
                    $searchTerms = [];
                }
                $normalized['search_terms'] = array_values(array_filter(array_map(fn ($term): ?string => is_string($term) ? trim($term) : null,
                    $searchTerms), fn (?string $term): bool => $term !== null && $term !== ''));
                $items[] = $normalized;
            }
            if ($items !== []) {
                $mergedTerms = [];
                foreach ($items as $item) {
                    foreach ($item['search_terms'] as $term) {
                        if (! in_array($term, $mergedTerms, true)) {
                            $mergedTerms[] = $term;
                        }
                    }
                }
                $data['name'] = $items[0]['name'];
                $data['brand'] = $items[0]['brand'] ?? null;
                $data['package'] = $items[0]['package'] ?? null;
                $data['uncertain'] = $items[0]['uncertain'] || (bool) ($data['uncertain'] ?? false);
                $data['search_terms'] = $mergedTerms;
                $data['items'] = $items;
            }
        }

        if (is_array($data)) {
            $data['search_terms'] = $this->normalizeSearchTerms($data['search_terms'] ?? []);
            if (isset($data['brand']) && is_string($data['brand']) && trim($data['brand']) !== '') {
                $brand = trim($data['brand']);
                $data['search_terms'] = $this->appendSearchTerms($data['search_terms'], [$brand, Str::ascii(strtolower($brand))]);
            }
            $data['brand'] = is_string($data['brand'] ?? null) ? trim($data['brand']) : null;
            $data['package'] = is_string($data['package'] ?? null) ? trim($data['package']) : null;
        }

        $validator = Validator::make(is_array($data) ? $data : [], [
            'name' => ['present', 'nullable', 'string', 'max:120'], 'brand' => ['present', 'nullable', 'string', 'max:100'],
            'package' => ['present', 'nullable', 'string', 'max:100'], 'uncertain' => ['required', 'boolean'],
            'search_terms' => ['required', 'array', 'max:20'], 'search_terms.*' => ['string', 'max:80'],
            'items' => ['sometimes', 'array', 'max:10'], 'items.*.name' => ['nullable', 'string', 'max:120'],
            'items.*.brand' => ['nullable', 'string', 'max:100'], 'items.*.package' => ['nullable', 'string', 'max:100'],
            'items.*.uncertain' => ['boolean'], 'items.*.search_terms' => ['array', 'max:20'], 'items.*.search_terms.*' => ['string', 'max:80'],
        ]);
        if ($validator->fails() || empty($data['name'])) {
            throw new AssistantFailure('Mahsulot tanilmadi. Yorliqni yaqinroq ko‘rsating yoki nomini yozing.', 422);
        }
        if (preg_match('/\p{Cyrillic}/u', $data['name']) === 1) {
            throw new AssistantFailure('AI mahsulot nomini o‘zbek lotin yozuvida aniq qaytara olmadi. Boshqa surat bilan urinib ko‘ring.', 422);
        }

        return $validator->validated();
    }

    private function appendSearchTerms(array $existing, array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (! in_array($candidate, $existing, true)) {
                $existing[] = $candidate;
            }
        }

        return $this->normalizeSearchTerms($existing);
    }

    private function normalizeSearchTerms(array $terms): array
    {
        return array_values(array_unique(array_filter(array_map(function ($term): ?string {
            if (! is_string($term)) {
                return null;
            }
            $term = trim($term);

            return $term === '' ? null : $term;
        }, $terms), fn (?string $term): bool => $term !== null && $term !== '')));
    }

    private function packageVariants(string $package): array
    {
        $trimmed = trim($package);
        if ($trimmed === '') {
            return [];
        }

        $variants = [$trimmed, strtolower($trimmed)];

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\s*(l|litre|liter|ltr|ml|gr|kg|g|dona|pcs|pack|banka|bottle)$/i', $trimmed, $match) === 1) {
            $value = str_replace(',', '.', $match[1]);
            $unit = strtolower($match[2]);
            $variants[] = $value.' '.$unit;
            $variants[] = $value.$unit;
            if (in_array($unit, ['l', 'litre', 'liter', 'ltr'], true)) {
                $ml = (float) $value * 1000;
                $variants[] = rtrim(rtrim(sprintf('%.0f', $ml), '0'), '.').' ml';
                $variants[] = sprintf('%.0f', $ml).'ml';
            }
            if (in_array($unit, ['ml'], true)) {
                $l = (float) $value / 1000;
                $variants[] = rtrim(rtrim((string) $l, '0'), '.').' l';
                $variants[] = rtrim(rtrim((string) $l, '0'), '.').'l';
            }
        }

        return array_values(array_unique(array_filter($variants, fn ($value): bool => is_string($value) && trim($value) !== '')));
    }
}
