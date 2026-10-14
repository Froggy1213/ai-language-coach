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
    /** @var list<string> */
    private array $staged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['deepgram-laravel.api_key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        foreach ($this->staged as $path) {
            @unlink($path);
        }

        $this->staged = [];

        parent::tearDown();
    }

    public function test_it_posts_the_recording_itself_and_returns_the_transcript(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload('I have been learning English for six years.'))]);

        $transcription = $this->transcriber()->transcribe(
            new Recording(path: $this->stage('fake-webm-bytes'), contentType: 'audio/webm'),
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
                && str_contains($request->url(), 'language=en')
                && str_contains($request->url(), 'punctuate=true')
                && str_contains($request->url(), 'filler_words=true')
                && $request->hasHeader('Authorization', 'Token test-key')
                && $request->hasHeader('Content-Type', 'audio/webm')
                && $request->body() === 'fake-webm-bytes';
        });
    }

    public function test_it_uses_the_model_named_in_the_deepgram_configuration(): void
    {
        config(['deepgram-laravel.default_model' => 'nova-3']);

        Http::fake(['api.deepgram.com/*' => Http::response($this->payload('Hello there.'))]);

        $this->transcriber()->transcribe(new Recording(path: $this->stage('bytes'), contentType: 'audio/webm'));

        // The model is configuration, not a constant in this class: a second
        // place to change it is a second place to forget.
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'model=nova-3'));
    }

    public function test_it_reads_the_fluency_evidence_out_of_the_word_timings(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload(
            'I, um, have been learning English for six years.',
            words: [
                ['word' => 'i', 'punctuated_word' => 'I', 'start' => 0.0, 'end' => 0.2, 'confidence' => 0.99],
                ['word' => 'um', 'punctuated_word' => 'um', 'start' => 1.4, 'end' => 1.6, 'confidence' => 0.92],
                ['word' => 'have', 'punctuated_word' => 'have', 'start' => 1.7, 'end' => 2.1, 'confidence' => 0.98],
                ['word' => 'been', 'punctuated_word' => 'been', 'start' => 2.2, 'end' => 4.4, 'confidence' => 0.71],
                ['word' => 'learning', 'punctuated_word' => 'learning', 'start' => 4.5, 'end' => 6.9, 'confidence' => 0.97],
            ],
            duration: 10.0,
        ))]);

        $features = $this->transcriber()
            ->transcribe(new Recording(path: $this->stage('bytes'), contentType: 'audio/webm'), 'en')
            ->features;

        $this->assertSame(5, $features->wordCount);
        $this->assertSame(['um'], $features->fillers);
        $this->assertSame(0.2, $features->lowConfidenceShare);
        $this->assertSame(['been'], $features->uncertainWords);
        // The 1.2 s stall between "I" (ends 0.2) and "um" (starts 1.4) — the gap
        // where the learner was hunting for the next word.
        $this->assertSame(1.2, $features->longestPauseSeconds);
        // Speaking time is the words' own duration, not the span from the first
        // word to the last: 0.2+0.2+0.4+2.2+2.4 = 5.4 of the 10-second clip. The
        // span would have called 6.9 seconds of it speech and 31% of the clip
        // silent instead of 46%.
        $this->assertSame(5.4, $features->speakingSeconds);
        $this->assertSame(0.46, $features->silenceShare);
        // 3.1 s of silence after the last word, against the 1.2 s stall in the
        // middle: a learner who had finished, not one who was stuck.
        $this->assertSame(3.1, $features->longestSilenceSeconds);
        $this->assertSame(55.6, $features->wordsPerMinute);
    }

    public function test_it_reports_no_measurements_when_the_response_carries_no_word_timings(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload('Hello.'))]);

        $features = $this->transcriber()
            ->transcribe(new Recording(path: $this->stage('bytes'), contentType: 'audio/webm'))
            ->features;

        // A zero would be a measurement the provider never made, and the
        // examiner prompt would read it as a silent recording. The prompt only
        // ever sees this array through `isEmpty()`, which short-circuits first.
        $this->assertTrue($features->isEmpty());
        $this->assertSame(['words_recognised' => 0], $features->toPromptArray());
    }

    public function test_it_removes_the_staged_recording_after_the_call(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload('Hello.'))]);

        $path = $this->stage('fake-bytes');

        $this->transcriber()->transcribe(new Recording(path: $path, contentType: 'audio/wav'));

        $this->assertFileDoesNotExist($path);
    }

    public function test_it_removes_the_staged_recording_even_when_the_call_fails(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response(['err_msg' => 'Bad Request'], 400)]);

        $path = $this->stage('bytes');

        try {
            $this->transcriber()->transcribe(new Recording(path: $path, contentType: 'audio/webm'));
        } catch (RequestException) {
            // Expected: the assertion is about the file, not the failure.
        }

        $this->assertFileDoesNotExist($path);
    }

    public function test_it_fails_when_the_recording_produced_no_speech(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response($this->payload(''))]);

        $this->expectException(TranscriptionFailed::class);
        $this->expectExceptionMessage('empty transcript');

        $this->transcriber()->transcribe(new Recording(path: $this->stage('silence'), contentType: 'audio/webm'));
    }

    public function test_it_fails_when_deepgram_rejects_the_request(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response(['err_msg' => 'Bad Request'], 400)]);

        $this->expectException(RequestException::class);

        $this->transcriber()->transcribe(new Recording(path: $this->stage('bytes'), contentType: 'audio/webm'));
    }

    private function transcriber(): DeepgramTranscriber
    {
        return $this->app->make(DeepgramTranscriber::class);
    }

    /**
     * A file standing in for what S3AssessmentAudioStorage::fetch() streams to
     * disk, registered for cleanup if the call under test does not remove it.
     */
    private function stage(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'assessment-recording-');
        file_put_contents($path, $contents);

        $this->staged[] = $path;

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $words
     * @return array<string, mixed>
     */
    private function payload(string $transcript, float $duration = 42.5, array $words = []): array
    {
        return [
            'metadata' => ['duration' => $duration, 'channels' => 1],
            'results' => [
                'channels' => [
                    ['alternatives' => [['transcript' => $transcript, 'confidence' => 0.98, 'words' => $words]]],
                ],
            ],
        ];
    }
}
