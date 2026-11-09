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
  /**
   * How clearly the recording came through, word by word; null on an assessment
   * that finished before this was measured. Stored inside `raw_data` next to the
   * transcript it was measured from.
   */
  pronunciation?: Maybe<PronunciationFeedback>;
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

/** Visible fields the learner confirms (and may edit) after the AI drafts a custom scenario. */
export type ConfirmCustomScenarioInput = {
  aiRole: Scalars['String']['input'];
  code: Scalars['String']['input'];
  learnerGoal: Scalars['String']['input'];
  learnerRole: Scalars['String']['input'];
  level: CefrLevel;
  register: Scalars['String']['input'];
  setting: Scalars['String']['input'];
  summary: Scalars['String']['input'];
  title: Scalars['String']['input'];
  usefulPhrases: Array<Scalars['String']['input']>;
};

/**
 * Communication review of a scenario session, produced by the after-conversation
 * analysis (M6). It judges whether the learner achieved the scenario's goal, not
 * the grammar of individual utterances, so it is kept apart from `Mistake`.
 */
export type GoalReview = {
  __typename?: 'GoalReview';
  /** Whether the learner achieved the scenario's learner goal. */
  goalAchieved: Scalars['Boolean']['output'];
  /** Expressions from the scenario the learner could have used but did not. */
  missedExpressions: Array<Scalars['String']['output']>;
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
  /** CEFR band this point is taught at; null for the uncategorized sentinel. */
  level?: Maybe<CefrLevel>;
  /** Every one of these must be completed before this point should be practised. */
  prerequisites: Array<GrammarPoint>;
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
  /** True when recent mistakes indicate this topic needs attention. */
  needsPractice: Scalars['Boolean']['output'];
  /** Position within the roadmap. */
  orderIndex: Scalars['Int']['output'];
  /** What the voice agent asks the learner to talk about. */
  practicePrompt: Scalars['String']['output'];
  /** Number of mistakes recorded on this grammar point in the recent window. */
  recentMistakeCount: Scalars['Int']['output'];
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
   * Confirm and save an AI-drafted custom scenario (including any edits the
   * learner made to its visible fields) so it appears in the topic list and can
   * be practised.
   */
  confirmCustomScenario: Scenario;
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
   * Delete one of the authenticated learner's custom scenarios. Past voice
   * sessions that practised it keep their history.
   */
  deleteCustomScenario: Scalars['Boolean']['output'];
  /**
   * Ask the AI to expand a short learner prompt into a role-play scenario draft
   * awaiting the learner's confirmation. Passing an existing custom `code`
   * regenerates that scenario's draft in place.
   */
  draftCustomScenario: Scenario;
  /**
   * End the conversation the learner just finished. Moves the session to
   * `completed`, records its duration, deletes the LiveKit room so `room_finished`
   * cannot arrive minutes later, and queues the mistake analysis. Idempotent: a
   * session that is already terminal is returned unchanged.
   */
  finishVoiceSession: VoiceSession;
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
   * Open a spoken practice session for a lesson card or a scenario and return the
   * room and its access token. Exactly one of `lessonCardId` or `scenarioCode`
   * must be given: a lesson card exercises a grammar point, a scenario is a
   * role-play from the catalogue. Idempotent: while a session is pending or
   * active, calling this again with the same subject returns that same session
   * instead of opening a second room. Fails with the `VOICE_FLEET_BUSY` error
   * code when no agent worker is free.
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


export type MutationConfirmCustomScenarioArgs = {
  input: ConfirmCustomScenarioInput;
};


export type MutationCreateAssessmentUploadUrlArgs = {
  contentType: Scalars['String']['input'];
};


export type MutationDeleteAccountArgs = {
  confirmation: Scalars['String']['input'];
  password: Scalars['String']['input'];
};


export type MutationDeleteCustomScenarioArgs = {
  code: Scalars['String']['input'];
};


export type MutationDraftCustomScenarioArgs = {
  code?: InputMaybe<Scalars['String']['input']>;
  level?: InputMaybe<CefrLevel>;
  prompt: Scalars['String']['input'];
  register?: InputMaybe<Scalars['String']['input']>;
};


export type MutationFinishVoiceSessionArgs = {
  id: Scalars['ID']['input'];
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
  lessonCardId?: InputMaybe<Scalars['ID']['input']>;
  scenarioCode?: InputMaybe<Scalars['String']['input']>;
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

/** One word the recogniser was not confident about. */
export type PronouncedWord = {
  __typename?: 'PronouncedWord';
  /** Recogniser confidence in this word, 0 to 1. */
  confidence: Scalars['Float']['output'];
  /** The word as it was recognised. */
  word: Scalars['String']['output'];
};

/**
 * How much of a recording the speech recogniser was confident about.
 *
 * Evidence about the audio rather than a phonetic grade: microphone placement,
 * background noise, an unfamiliar accent and an unclear word all move it, which is
 * why the interface names the words instead of offering a score to chase.
 */
export type PronunciationFeedback = {
  __typename?: 'PronunciationFeedback';
  /** The sentence the analysis wrote about these words, in the interface language. */
  advice?: Maybe<Scalars['String']['output']>;
  /**
   * Overall clarity band — `clear`, `mostly_clear`, `uneven` or `effortful`;
   * null when the recording was too short for the share to mean anything.
   */
  band?: Maybe<Scalars['String']['output']>;
  /** Share of words the recogniser was confident about, whole percent; null when the sample was too short. */
  clarityPercent?: Maybe<Scalars['Int']['output']>;
  /** Whether the recording was too short to summarise, so only the words are shown. */
  isThinSample: Scalars['Boolean']['output'];
  /** Words below this confidence were listed; the interface explains the threshold with it. */
  uncertainThreshold: Scalars['Float']['output'];
  /** Words the recogniser was least sure about, worst first. */
  uncertainWords: Array<PronouncedWord>;
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
  /** Scenarios the learner can practise, for their target language. */
  scenarios: Array<Scenario>;
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

/** One role-play scenario the learner can practise. */
export type Scenario = {
  __typename?: 'Scenario';
  aiRole: Scalars['String']['output'];
  code: Scalars['String']['output'];
  /** Whether this scenario was created by the learner rather than the built-in catalogue. */
  isCustom: Scalars['Boolean']['output'];
  learnerGoal: Scalars['String']['output'];
  learnerRole: Scalars['String']['output'];
  level: CefrLevel;
  register: Scalars['String']['output'];
  setting: Scalars['String']['output'];
  summary: Scalars['String']['output'];
  title: Scalars['String']['output'];
  usefulPhrases: Array<Scalars['String']['output']>;
  /** The learner's original description when this is a custom scenario. */
  userPrompt?: Maybe<Scalars['String']['output']>;
};

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
  /**
   * The most recent assessment this learner finished, so the level screen can show
   * its result again after a page reload; null before the first one completes.
   */
  latestAssessment?: Maybe<Assessment>;
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
  /** Whether mistake analysis has finished for this session. */
  analyzed: Scalars['Boolean']['output'];
  /** Length of the conversation in seconds, once it finished. */
  durationSec?: Maybe<Scalars['Int']['output']>;
  /** Why the session failed, when it did. */
  failReason?: Maybe<Scalars['String']['output']>;
  /** Communication review of a scenario session; null until M6 analysis finishes and always null for lessons. */
  goalReview?: Maybe<GoalReview>;
  /** Unique primary key. */
  id: Scalars['ID']['output'];
  /** The lesson this session practises; null for a scenario session. */
  lessonCard?: Maybe<LessonCard>;
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
  /** Scenario this session practises, if it is a scenario session. */
  scenario?: Maybe<Scenario>;
  /** Lifecycle of the session. */
  status: VoiceSessionStatus;
};

export type VoiceSessionStatus =
  | 'abandoned'
  | 'active'
  | 'completed'
  | 'failed'
  | 'pending';
