<?php

namespace Tests\Feature\Assessments;

use App\Assessments\DeepgramTranscriber;
use App\Assessments\TranscriptionFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepgramTranscriberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['deepgram-laravel.api_key' => 'test-key']);
    }

    public function test_it_returns_the_transcript_deepgram_produced(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload('I have been learning English for six years.'))]);

        $transcription = $this->transcriber()->transcribe(
            'https://coach-audio.s3.amazonaws.com/assessments/7/recording.webm',
            'en',
        );

        $this->assertSame('I have been learning English for six years.', $transcription->text);
        $this->assertSame(42.5, $transcription->durationSeconds);
        $this->assertSame('I have been learning English for six years.', data_get($transcription->raw, 'results.channels.0.alternatives.0.transcript'));

        Http::assertSent(static function (Request $request): bool {
            return str_starts_with($request->url(), 'https://api.deepgram.com/v1/listen?')
                && str_contains($request->url(), 'model=nova-2')
                && str_contains($request->url(), 'language=en')
                && str_contains($request->url(), 'punctuate=true')
                && $request->hasHeader('Authorization', 'Token test-key')
                && $request['url'] === 'https://coach-audio.s3.amazonaws.com/assessments/7/recording.webm';
        });
    }

    public function test_it_fails_when_the_recording_produced_no_speech(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload(''))]);

        $this->expectException(TranscriptionFailed::class);
        $this->expectExceptionMessage('empty transcript');

        $this->transcriber()->transcribe('https://coach-audio.s3.amazonaws.com/assessments/7/silence.webm');
    }

    public function test_it_fails_when_deepgram_rejects_the_request(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response(['err_msg' => 'Bad Request'], 400)]);

        $this->expectException(RequestException::class);

        $this->transcriber()->transcribe('https://coach-audio.s3.amazonaws.com/assessments/7/recording.webm');
    }

    private function transcriber(): DeepgramTranscriber
    {
        return $this->app->make(DeepgramTranscriber::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $transcript, float $duration = 42.5): array
    {
        return [
            'metadata' => ['duration' => $duration, 'channels' => 1],
            'results' => [
                'channels' => [
                    ['alternatives' => [['transcript' => $transcript, 'confidence' => 0.98]]],
                ],
            ],
        ];
    }
}
