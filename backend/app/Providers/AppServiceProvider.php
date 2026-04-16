<?php

namespace App\Providers;

use App\Services\Chatbot\Contracts\ChatbotLanguageModel;
use App\Services\Chatbot\LanguageModels\NullChatbotLanguageModel;
use App\Services\Chatbot\LanguageModels\OpenAiCompatibleChatbotLanguageModel;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ChatbotLanguageModel::class, function () {
            $config = config('chatbot.llm', []);

            if (($config['provider'] ?? 'openai_compatible') === 'openai_compatible') {
                return new OpenAiCompatibleChatbotLanguageModel($config);
            }

            return new NullChatbotLanguageModel();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
