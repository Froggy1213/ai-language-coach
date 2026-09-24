<?php

namespace Tests\Feature\Assessments;

use App\Assessments\AudioUpload;
use App\Assessments\InvalidAssessmentAudio;
use App\Assessments\S3AssessmentAudioStorage;
use App\Models\User;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class S3AssessmentAudioStorageTest extends TestCase
{
    private const BUCKET = 'coach-audio';

    public function test_it_presigns_a_post_that_carries_the_size_and_content_type_limits(): void
    {
        $this->travelTo(Carbon::parse('2026-09-24 12:00:00'));
        $user = $this->user(7);

        $upload = $this->storage()->presignUpload($user, 'audio/webm;codecs=opus');

        $this->assertStringStartsWith('https://coach-audio.s3', $upload->uploadUrl);
        $this->assertSame('audio/webm', $upload->fields['Content-Type']);
        $this->assertStringStartsWith('assessments/7/', $upload->fields['key']);
        $this->assertStringEndsWith('.webm', $upload->fields['key']);
        $this->assertStringEndsWith('/'.$upload->fields['key'], $upload->fileUrl);
        $this->assertStringStartsWith('https://coach-audio.s3', $upload->fileUrl);
        $this->assertArrayHasKey('X-Amz-Signature', $upload->fields);

        $policy = $this->policy($upload->fields['Policy']);

        $this->assertSame('2026-09-24T12:10:00Z', $policy['expiration']);
        $this->assertContains(['eq', '$bucket', self::BUCKET], $policy['conditions']);
        $this->assertContains(['content-length-range', 1, 15 * 1024 * 1024], $policy['conditions']);
        $this->assertContains(['eq', '$Content-Type', 'audio/webm'], $policy['conditions']);
        $this->assertContains(['eq', '$key', $upload->fields['key']], $policy['conditions']);
    }

    public function test_it_namespaces_every_upload_under_the_learner(): void
    {
        $upload = $this->storage()->presignUpload($this->user(42), 'audio/wav');

        $this->assertStringStartsWith('assessments/42/', $upload->fields['key']);
        $this->assertStringEndsWith('.wav', $upload->fields['key']);
    }

    public function test_it_reports_what_the_bucket_holds_for_an_upload(): void
    {
        $user = $this->user(7);
        $key = 'assessments/7/01J8ULID.webm';

        $upload = $this->storage($this->headHandler(2048, 'audio/webm'))
            ->inspect($user, 'https://coach-audio.s3.us-east-1.amazonaws.com/'.$key);

        $this->assertSame($key, $upload->key);
        $this->assertSame(2048, $upload->sizeBytes);
        $this->assertSame('audio/webm', $upload->contentType);
    }

    public function test_it_reads_a_path_style_url_where_the_bucket_leads_the_path(): void
    {
        $user = $this->user(7);

        // MinIO, and AWS when AWS_USE_PATH_STYLE_ENDPOINT is on, hand out URLs
        // with the bucket as the first path segment.
        $upload = $this->storage($this->headHandler(2048))
            ->inspect($user, 'http://localhost:9000/coach-audio/assessments/7/recording.webm');

        $this->assertSame('assessments/7/recording.webm', $upload->key);
    }

    public function test_it_normalises_the_content_type_the_bucket_reports(): void
    {
        $user = $this->user(7);

        $upload = $this->storage($this->headHandler(2048, 'audio/webm;codecs=opus'))
            ->inspect($user, 'https://coach-audio.s3.us-east-1.amazonaws.com/assessments/7/recording.webm');

        $this->assertSame('audio/webm', $upload->contentType);
    }

    public function test_it_rejects_an_upload_that_belongs_to_another_learner(): void
    {
        $this->expectException(InvalidAssessmentAudio::class);
        $this->expectExceptionMessage('does not belong to this learner');

        $this->storage()->inspect(
            $this->user(8),
            'https://coach-audio.s3.us-east-1.amazonaws.com/assessments/7/recording.webm',
        );
    }

    public function test_it_rejects_a_url_that_is_not_an_assessment_upload(): void
    {
        $this->expectException(InvalidAssessmentAudio::class);
        $this->expectExceptionMessage('not an assessment upload URL');

        $this->storage()->inspect(
            $this->user(7),
            'https://coach-audio.s3.us-east-1.amazonaws.com/avatars/7/photo.png',
        );
    }

    public function test_it_rejects_an_object_that_is_not_in_the_bucket(): void
    {
        $handler = new MockHandler;
        $handler->append(new S3Exception('Not Found', new Command('HeadObject', [
            'Bucket' => self::BUCKET,
            'Key' => 'assessments/7/missing.webm',
        ])));

        $this->expectException(InvalidAssessmentAudio::class);
        $this->expectExceptionMessage('could not be found');

        $this->storage($handler)->inspect(
            $this->user(7),
            'https://coach-audio.s3.us-east-1.amazonaws.com/assessments/7/missing.webm',
        );
    }

    public function test_it_rejects_an_empty_object(): void
    {
        $this->expectException(InvalidAssessmentAudio::class);
        $this->expectExceptionMessage('is empty');

        $this->storage($this->headHandler(0))
            ->inspect($this->user(7), 'https://coach-audio.s3.us-east-1.amazonaws.com/assessments/7/silence.webm');
    }

    public function test_it_rejects_an_object_larger_than_the_limit(): void
    {
        config(['assessments.max_size_bytes' => 1024]);

        $this->expectException(InvalidAssessmentAudio::class);
        $this->expectExceptionMessage('larger than');

        $this->storage($this->headHandler(2048))
            ->inspect($this->user(7), 'https://coach-audio.s3.us-east-1.amazonaws.com/assessments/7/long.webm');
    }

    public function test_it_rejects_a_content_type_it_cannot_transcribe(): void
    {
        $this->expectException(InvalidAssessmentAudio::class);
        $this->expectExceptionMessage('cannot be transcribed');

        $this->storage($this->headHandler(2048, 'application/pdf'))
            ->inspect($this->user(7), 'https://coach-audio.s3.us-east-1.amazonaws.com/assessments/7/notes.webm');
    }

    public function test_it_reads_the_recording_back_out_of_the_bucket(): void
    {
        $handler = new MockHandler;
        $handler->append(new Result(['Body' => Utils::streamFor('fake-webm-bytes')]));

        $recording = $this->storage($handler)->fetch($this->upload());

        $this->assertSame('fake-webm-bytes', $recording->contents);
        $this->assertSame('audio/webm', $recording->contentType);
        $this->assertSame('GetObject', $handler->getLastCommand()->getName());
    }

    public function test_it_fails_when_the_recording_cannot_be_read_back(): void
    {
        $handler = new MockHandler;
        $handler->append(new S3Exception('Forbidden', new Command('GetObject', [
            'Bucket' => self::BUCKET,
            'Key' => 'assessments/7/recording.webm',
        ])));

        $this->expectException(InvalidAssessmentAudio::class);
        $this->expectExceptionMessage('could not be read back');

        $this->storage($handler)->fetch($this->upload());
    }

    public function test_it_deletes_the_recording_from_the_bucket(): void
    {
        $handler = new MockHandler;
        $handler->append(new Result([]));

        $this->storage($handler)->delete(
            'https://coach-audio.s3.us-east-1.amazonaws.com/assessments/7/recording.webm',
        );

        $this->assertSame('DeleteObject', $handler->getLastCommand()->getName());
    }

    private function storage(?MockHandler $handler = null): S3AssessmentAudioStorage
    {
        $config = [
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        ];

        if ($handler instanceof MockHandler) {
            $config['handler'] = $handler;
        }

        return new S3AssessmentAudioStorage(new S3Client($config), self::BUCKET);
    }

    public function test_it_names_the_missing_bucket_instead_of_failing_on_a_signature(): void
    {
        $storage = new S3AssessmentAudioStorage(new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        ]), '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('set AWS_BUCKET');

        $storage->presignUpload($this->user(7), 'audio/webm');
    }

    private function headHandler(int $size, string $contentType = 'audio/webm'): MockHandler
    {
        $handler = new MockHandler;
        $handler->append(new Result([
            'ContentLength' => $size,
            'ContentType' => $contentType,
        ]));

        return $handler;
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id, 'target_language' => 'en']);
    }

    private function upload(): AudioUpload
    {
        return new AudioUpload(
            key: 'assessments/7/recording.webm',
            fileUrl: 'https://coach-audio.s3.us-east-1.amazonaws.com/assessments/7/recording.webm',
            sizeBytes: 15,
            contentType: 'audio/webm',
        );
    }

    /**
     * @return array{expiration: string, conditions: list<array<int, mixed>>}
     */
    private function policy(string $encoded): array
    {
        /** @var array{expiration: string, conditions: list<array<int, mixed>>} $policy */
        $policy = json_decode(base64_decode($encoded, true) ?: '', true, flags: JSON_THROW_ON_ERROR);

        return $policy;
    }
}
