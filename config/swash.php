<?php

return [
    'openai_key' => env('OPENAI_API_KEY'),
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
    'image_model_transparent' => env('OPENAI_IMAGE_MODEL_TRANSPARENT', 'gpt-image-1.5'),
    'image_daily_limit' => (int) env('IMAGE_DAILY_LIMIT', 200),

    'type_pairs' => [
        'editorial-serif' => [
            'heading' => '"Noto Serif", Georgia, serif',
            'body' => '"Noto Sans", system-ui, sans-serif',
            'google' => 'Noto+Serif:wght@600;700&family=Noto+Sans:wght@400;600',
        ],
        'modern-sans' => [
            'heading' => 'Inter, system-ui, sans-serif',
            'body' => 'Inter, system-ui, sans-serif',
            'google' => 'Inter:wght@400;600;800',
        ],
        'technical' => [
            'heading' => '"IBM Plex Sans", system-ui, sans-serif',
            'body' => '"IBM Plex Sans", system-ui, sans-serif',
            'google' => 'IBM+Plex+Sans:wght@400;600;700&family=IBM+Plex+Mono:wght@400;500',
        ],
        'warm-humanist' => [
            'heading' => 'Lora, Georgia, serif',
            'body' => '"Source Sans 3", system-ui, sans-serif',
            'google' => 'Lora:wght@600;700&family=Source+Sans+3:wght@400;600',
        ],
        'bold-display' => [
            'heading' => '"Playfair Display", Georgia, serif',
            'body' => 'Inter, system-ui, sans-serif',
            'google' => 'Playfair+Display:wght@700;900&family=Inter:wght@400;600',
        ],
    ],
];
