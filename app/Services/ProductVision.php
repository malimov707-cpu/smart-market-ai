<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

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
        $result = $this->assistant->answer('Mahsulotni katalogdan qidirish uchun suratdagi ko‘rinadigan yorliq va belgilar asosida aniqlang. Mahsulot nomini tushunarli, tabiiy o‘zbek lotin yozuvida qaytaring; ruscha tovushlarni o‘zbekcha harflarga ko‘chirib, g‘alati nom tuzmang. Faqat ko‘rinib turgan ma’lumotni kiriting, noaniq bo‘lsa uncertain=true deb belgilang. Narx, yashirin matn yoki ko‘rinmayotgan shtrix-kod ma’lumotini taxmin qilmang.', [], [], 'data:image/jpeg;base64,'.base64_encode($jpeg));
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($result['answer']));
        try {
            $data = json_decode($text, true, 12, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AssistantFailure('AI mahsulotni aniq formatda taniy olmadi. Boshqa rakursdan suratga oling.');
        }
        $validator = Validator::make(is_array($data) ? $data : [], [
            'name' => ['present', 'nullable', 'string', 'max:120'], 'brand' => ['present', 'nullable', 'string', 'max:100'],
            'package' => ['present', 'nullable', 'string', 'max:100'], 'uncertain' => ['required', 'boolean'],
            'search_terms' => ['required', 'array', 'max:3'], 'search_terms.*' => ['string', 'max:80'],
        ]);
        if ($validator->fails() || empty($data['name'])) {
            throw new AssistantFailure('Mahsulot tanilmadi. Yorliqni yaqinroq ko‘rsating yoki nomini yozing.', 422);
        }
        if (preg_match('/\p{Cyrillic}/u', $data['name']) === 1) {
            throw new AssistantFailure('AI mahsulot nomini o‘zbek lotin yozuvida aniq qaytara olmadi. Boshqa surat bilan urinib ko‘ring.', 422);
        }

        return $validator->validated();
    }
}
