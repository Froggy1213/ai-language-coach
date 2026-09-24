<?php

namespace Tests\Feature\Assessments;

use App\Assessments\DeepgramTranscriber;
use App\Assessments\Recording;
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

    public function test_it_posts_the_recording_itself_and_returns_the_transcript(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload('I have been learning English for six years.'))]);

        $transcription = $this->transcriber()->transcribe(
            new Recording(contents: 'fake-webm-bytes', contentType: 'audio/webm'),
            'en',
        );

        $this->assertSame('I have been learning English for six years.', $transcription->text);
        $this->assertSame(42.5, $transcription->durationSeconds);
        $this->assertSame(
            'I have been learning English for six years.',
            data_get($transcription->raw, 'results.channels.0.alternatives.0.transcript'),
        );

        // The audio travels in the body: the bucket is private, so a URL would
        // have come back 403 to Deepgram.
        Http::assertSent(static function (Request $request): bool {
            return str_starts_with($request->url(), 'https://api.deepgram.com/v1/listen?')
                && str_contains($request->url(), 'model=nova-2')
                && str_contains($request->url(), 'language=en')
                && str_contains($request->url(), 'punctuate=true')
                && $request->hasHeader('Authorization', 'Token test-key')
                && $request->hasHeader('Content-Type', 'audio/webm')
                && $request->body() === 'fake-webm-bytes';
        });
    }

    public function test_it_removes_the_spooled_recording_after_the_call(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload('Hello.'))]);

        $this->transcriber()->transcribe(new Recording(contents: 'fake-bytes', contentType: 'audio/wav'));

        $this->assertSame([], glob(sys_get_temp_dir().'/assessment-recording-*'));
    }

    public function test_it_fails_when_the_recording_produced_no_speech(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload(''))]);

        $this->expectException(TranscriptionFailed::class);
        $this->expectExceptionMessage('empty transcript');

        $this->transcriber()->transcribe(new Recording(contents: 'silence', contentType: 'audio/webm'));
    }

    public function test_it_fails_when_deepgram_rejects_the_request(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response(['err_msg' => 'Bad Request'], 400)]);

        $this->expectException(RequestException::class);

        $this->transcriber()->transcribe(new Recording(contents: 'bytes', contentType: 'audio/webm'));
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
