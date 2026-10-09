// GENERATED FILE — DO NOT EDIT. Run `npm run codegen` after changing backend/graphql/**.
export type Maybe<T> = T | null;
export type InputMaybe<T> = T | null;
/** All built-in and custom scalars, mapped to their actual values */
export type Scalars = {
  ID: { input: string; output: string; }
  String: { input: string; output: string; }
  Boolean: { input: boolean; output: boolean; }
  Int: { input: number; output: number; }
  Float: { input: number; output: number; }
  /** A datetime string with format `Y-m-d H:i:s`, e.g. `2018-05-23 13:43:32`. */
  DateTime: { input: string; output: string; }
};

/** One onboarding recording and the async analysis it produced (plan §5). */
export type Assessment = {
  __typename?: 'Assessment';
  /** CEFR band the analysis settled on; null until `status` is `done`. */
  cefrLevel?: Maybe<CefrLevel>;
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** Where the transcription and analysis pass has got to. */
  status: AssessmentStatus;
};

export type AssessmentStatus =
  | 'done'
  | 'failed'
  | 'processing';

export type CefrLevel =
  | 'A1'
  | 'A2'
  | 'B1'
  | 'B2'
  | 'C1';

/** Reference material generated for a lesson card. */
export type CheatSheet = {
  __typename?: 'CheatSheet';
  /** Example sentences. */
  examples: Array<Scalars['String']['output']>;
  /** Pattern to apply, e.g. `have/has + past participle`. */
  formula: Scalars['String']['output'];
  /** Common mistakes to avoid. */
  pitfalls: Array<Scalars['String']['output']>;
  /** The rule in one sentence. */
  rule: Scalars['String']['output'];
};

/** Canonical grammar topic mistakes are classified against (plan §5). */
export type GrammarPoint = {
  __typename?: 'GrammarPoint';
  /** Grouping used on the review screen. */
  category: Scalars['String']['output'];
  /** Stable code used to match LLM output, `uncategorized` for the sentinel. */
  code: Scalars['String']['output'];
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** Language this point belongs to. */
  language: Scalars['String']['output'];
  /** Human-readable title. */
  title: Scalars['String']['output'];
};

/** One grammar point to practise, with its cheat sheet. */
export type LessonCard = {
  __typename?: 'LessonCard';
  /** Reference material for the lesson. */
  cheatSheet: CheatSheet;
  /** Grammar point this card teaches. */
  grammarPoint: GrammarPoint;
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** Position within the roadmap. */
  orderIndex: Scalars['Int']['output'];
  /** What the voice agent asks the learner to talk about. */
  practicePrompt: Scalars['String']['output'];
  /** Whether the card can be practised yet. */
  status: LessonCardStatus;
};

export type LessonCardStatus =
  | 'completed'
  | 'locked'
  | 'ready';

/** An error the user made in a voice session, classified by grammar point. */
export type Mistake = {
  __typename?: 'Mistake';
  /** Corrected version of the utterance. */
  correction: Scalars['String']['output'];
  /** Why the correction is right. */
  explanation: Scalars['String']['output'];
  /** Grammar point the mistake was matched to. */
  grammarPoint: GrammarPoint;
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** What the user said. */
  userUtterance: Scalars['String']['output'];
};

export type Mutation = {
  __typename?: 'Mutation';
  /**
   * Mark a lesson card as completed and unlock the next card in sequence (order_index + 1)
   * inside a database transaction under pessimistic locking. Idempotent: repeating on
   * an already completed card leaves it completed and returns it.
   */
  completeLessonCard: LessonCard;
  /**
   * Presign one S3 POST for an onboarding recording. The signature carries the
   * size and content-type limits, so the bucket rejects a bad upload itself.
   */
  createAssessmentUploadUrl: PresignedUpload;
  /**
   * Delete the authenticated user's account and all associated personal data.
   * Requires password verification and exact confirmation string `DELETE`.
   */
  deleteAccount: Scalars['Boolean']['output'];
  /**
   * Build the roadmap for the authenticated user's current CEFR band, or return
   * the one they already have. Idempotent, so a retry after a timeout is safe.
   */
  generateRoadmap: Roadmap;
  /** Sign in and start a session (Sanctum SPA cookie). */
  login: User;
  /** End the current session. */
  logout: Scalars['Boolean']['output'];
  /** Create an account and sign in. */
  register: User;
  /**
   * Open a spoken practice session for a lesson card and return the room and its
   * access token. Idempotent: while a session is pending or active, calling this
   * again returns that same session instead of opening a second room. Fails with
   * the `VOICE_FLEET_BUSY` error code when no agent worker is free.
   */
  requestVoiceToken: VoiceSession;
  /**
   * Hand an uploaded recording to the transcription and CEFR analysis job. The
   * answer arrives through the `assessmentReady` subscription.
   */
  submitAssessment: Assessment;
  /**
   * Submit the learner's self-assessed recall quality (0 to 5) for a grammar point
   * and recompute its spaced repetition schedule using the SM-2 algorithm.
   */
  submitReviewResult: ReviewItem;
};


export type MutationCompleteLessonCardArgs = {
  lessonCardId: Scalars['ID']['input'];
};


export type MutationCreateAssessmentUploadUrlArgs = {
  contentType: Scalars['String']['input'];
};


export type MutationDeleteAccountArgs = {
  confirmation: Scalars['String']['input'];
  password: Scalars['String']['input'];
};


export type MutationLoginArgs = {
  email: Scalars['String']['input'];
  password: Scalars['String']['input'];
};


export type MutationRegisterArgs = {
  email: Scalars['String']['input'];
  name: Scalars['String']['input'];
  password: Scalars['String']['input'];
  targetLanguage: Scalars['String']['input'];
};


export type MutationRequestVoiceTokenArgs = {
  lessonCardId: Scalars['ID']['input'];
};


export type MutationSubmitAssessmentArgs = {
  audioUrl: Scalars['String']['input'];
};


export type MutationSubmitReviewResultArgs = {
  grammarPointId: Scalars['ID']['input'];
  quality: Scalars['Int']['input'];
};

/**
 * Presigned S3 POST for one recording. The policy behind the signature carries the
 * size and content-type limits, so S3 rejects a bad upload on its own (plan §5).
 */
export type PresignedUpload = {
  __typename?: 'PresignedUpload';
  /** Form fields to send before the file part. */
  fields: Array<UploadField>;
  /** Object URL once the upload succeeded — pass it to `submitAssessment`. */
  fileUrl: Scalars['String']['output'];
  /** URL the multipart form posts to. */
  uploadUrl: Scalars['String']['output'];
};

export type Query = {
  __typename?: 'Query';
  /** Review items whose review date has arrived, soonest first. */
  dueReviews: Array<ReviewItem>;
  /** The currently authenticated user. */
  me: User;
  /** The authenticated user's mistakes, newest first. */
  mistakes: Array<Mistake>;
  /** Grammar points the learner got wrong in at least N distinct sessions within the rolling window. */
  recurringMistakes: Array<RecurringMistake>;
  /** The roadmap the authenticated user is working through, if one has been generated yet. */
  roadmap?: Maybe<Roadmap>;
  /** A voice session belonging to the authenticated user, if it exists. */
  voiceSession?: Maybe<VoiceSession>;
};


export type QueryMistakesArgs = {
  grammarPointId?: InputMaybe<Scalars['ID']['input']>;
};


export type QueryVoiceSessionArgs = {
  id: Scalars['ID']['input'];
};

/** A grammar point the learner keeps getting wrong (plan §3, §7). */
export type RecurringMistake = {
  __typename?: 'RecurringMistake';
  grammarPoint: GrammarPoint;
  lastMistakeAt: Scalars['String']['output'];
  mistakeCount: Scalars['Int']['output'];
  sessionCount: Scalars['Int']['output'];
};

/** Spaced repetition state for one (user, grammar point) pair (plan §5). */
export type ReviewItem = {
  __typename?: 'ReviewItem';
  /** SM-2 ease factor. */
  easeFactor: Scalars['Float']['output'];
  /** Grammar point to review. */
  grammarPoint: GrammarPoint;
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** Current interval between repetitions, in days. */
  intervalDays: Scalars['Int']['output'];
  /** When the item is due next. */
  nextReviewAt: Scalars['String']['output'];
  /** Number of consecutive successful reviews. */
  repetitionNumber: Scalars['Int']['output'];
};

/** Ordered study plan generated from the CEFR assessment. */
export type Roadmap = {
  __typename?: 'Roadmap';
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** Lesson cards in the order they should be studied. */
  lessonCards: Array<LessonCard>;
  /** Lifecycle of the roadmap. */
  status: RoadmapStatus;
  /** Title shown on the roadmap screen. */
  title: Scalars['String']['output'];
};

export type RoadmapStatus =
  | 'active'
  | 'archived'
  | 'completed';

export type Subscription = {
  __typename?: 'Subscription';
  /**
   * Fires when the learner's assessment finished. Nullable on purpose: at
   * subscribe time the field resolves to null, and a non-null type would reject
   * the subscription response (plan §5).
   */
  assessmentReady?: Maybe<Assessment>;
  /**
   * Fires when a voice session's feedback and mistake analysis is ready.
   * Nullable on purpose: at subscribe time the field resolves to null, and
   * a non-null type would reject the subscription response (plan §5).
   */
  sessionFeedbackReady?: Maybe<VoiceSession>;
};


export type SubscriptionAssessmentReadyArgs = {
  userId: Scalars['ID']['input'];
};


export type SubscriptionSessionFeedbackReadyArgs = {
  sessionId: Scalars['ID']['input'];
};

/** One form field the browser must send alongside the file part. */
export type UploadField = {
  __typename?: 'UploadField';
  /** Form field name, e.g. `policy` or `X-Amz-Signature`. */
  name: Scalars['String']['output'];
  /** Value to send verbatim. */
  value: Scalars['String']['output'];
};

/** Account of a person learning a language. */
export type User = {
  __typename?: 'User';
  /** Current CEFR band, set by the onboarding assessment. */
  currentLevel: CefrLevel;
  /** Account email address. */
  email: Scalars['String']['output'];
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** Display name. */
  name: Scalars['String']['output'];
  /** The roadmap generated for this user. */
  roadmap?: Maybe<Roadmap>;
  /** Language the user is learning. */
  targetLanguage: Scalars['String']['output'];
  /** When the learner gave consent to voice audio recording and processing, if recorded. */
  voiceConsentAt?: Maybe<Scalars['DateTime']['output']>;
};

/** One spoken practice session with the voice agent (plan §4). */
export type VoiceSession = {
  __typename?: 'VoiceSession';
  /** Length of the conversation in seconds, once it finished. */
  durationSec?: Maybe<Scalars['Int']['output']>;
  /** Why the session failed, when it did. */
  failReason?: Maybe<Scalars['String']['output']>;
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** The lesson this session practises. */
  lessonCard: LessonCard;
  /**
   * Short-lived access token scoped to this session's room. Minted when the
   * field is read, never stored: a token saved at session creation would already
   * be expired for a learner returning to an idle session.
   */
  livekitToken?: Maybe<Scalars['String']['output']>;
  /**
   * WebSocket endpoint the browser dials. Returned per session rather than read
   * from the client's build-time environment, so a deployment can move the
   * LiveKit node without rebuilding the frontend.
   */
  livekitUrl: Scalars['String']['output'];
  /** Mistakes the async analysis found in this session. */
  mistakes: Array<Mistake>;
  /** Lifecycle of the session. */
  status: VoiceSessionStatus;
};

export type VoiceSessionStatus =
  | 'abandoned'
  | 'active'
  | 'completed'
  | 'failed'
  | 'pending';
