<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestOpenAICommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:openai';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test OpenAI API connection and key validity';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing OpenAI API connection...');
        
        $apiKey = config('services.openai.api_key');
        $chatApiUrl = config('services.openai.chat_api_url');
        
        $this->line("API Key: " . substr($apiKey, 0, 20) . "...");
        $this->line("API URL: " . $chatApiUrl);
        
        if (empty($apiKey) || $apiKey === 'your-openai-api-key-here') {
            $this->error('OpenAI API key is not configured properly!');
            $this->line('Please set OPENAI_API_KEY in your .env file');
            return 1;
        }
        
        try {
            $this->info('Making test API call...');
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($chatApiUrl, [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => 'Hello, this is a test message. Please respond with "API connection successful".'
                        ]
                    ],
                    'max_tokens' => 50,
                    'temperature' => 0.1
                ]);
            
            $statusCode = $response->status();
            $this->line("Status Code: " . $statusCode);
            
            if ($statusCode === 200) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? 'No content';
                $this->info('✅ OpenAI API connection successful!');
                $this->line("Response: " . $content);
                return 0;
            } else {
                $this->error('❌ OpenAI API call failed!');
                $this->line("Status: " . $statusCode);
                $this->line("Response: " . $response->body());
                
                if ($statusCode === 401) {
                    $this->error('Authentication failed. Please check your API key.');
                    $this->line('Make sure your API key is valid and has sufficient credits.');
                } elseif ($statusCode === 429) {
                    $this->error('Rate limit exceeded. Please try again later.');
                } elseif ($statusCode === 500) {
                    $this->error('OpenAI server error. Please try again later.');
                }
                
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Exception occurred: ' . $e->getMessage());
            Log::error('OpenAI test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}
