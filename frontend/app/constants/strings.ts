/**
 * All interface copy, in one file.
 *
 * Two rules keep this honest:
 *  - The interface language is Japanese. The *learning* content (grammar titles,
 *    practice prompts, cheat sheets) is English and comes from the API — never
 *    translate it here.
 *  - Microcopy uses plain polite Japanese (です・ます). No honorific padding, no
 *    katakana loanwords where a plain native word reads more clearly.
 */

export const app = {
  name: 'Kirie',
  tagline: '声に出して、英語を身につける。',
} as const

export const nav = {
  today: '今日',
  roadmap: 'ロードマップ',
  talk: '会話',
  review: '復習',
  settings: '設定',
} as const

export const common = {
  retryShort: '再試行',
  refresh: '更新',
  refreshing: '更新中…',
  backToRoadmap: 'ロードマップへ',
  theme: 'テーマ',
  themeDark: 'ダーク',
  themeLight: 'ライト',
} as const

export const login = {
  title: 'おかえりなさい',
  subtitle: 'ロードマップの続きから再開できます。',
  email: 'メールアドレス',
  password: 'パスワード',
  forgotPassword: 'パスワードをお忘れですか？',
  submit: 'ログイン',
  pending: 'ログインしています…',
  noAccount: 'アカウントをお持ちでないですか？',
  toRegister: '新規登録',
  resetSuccess: 'パスワードを再設定しました。新しいパスワードでログインしてください。',
  error: 'ログインできませんでした。',
} as const

export const forgotPassword = {
  title: 'パスワードの再設定',
  subtitle: '登録済みのメールアドレスを入力してください。再設定用のリンクをお送りします。',
  email: 'メールアドレス',
  submit: '再設定メールを送信',
  pending: '送信しています…',
  sentNotice: '入力されたメールアドレスが登録されている場合、再設定用のリンクを送信しました。受信トレイをご確認ください。',
  backToLogin: 'ログインに戻る',
  error: '再設定メールを送信できませんでした。しばらくしてからお試しください。',
} as const

export const resetPassword = {
  title: '新しいパスワードを設定',
  subtitle: '8文字以上の新しいパスワードを入力してください。',
  newPassword: '新しいパスワード',
  passwordHint: '8文字以上。',
  confirmPassword: '新しいパスワード（確認）',
  passwordMismatch: '新しいパスワードと確認用パスワードが一致しません。',
  submit: 'パスワードを再設定',
  pending: '再設定しています…',
  invalidLink: '再設定リンクが無効か、必要な情報が不足しています。もう一度リクエストしてください。',
  requestNewLink: '再設定メールをもう一度リクエスト',
  error: 'パスワードを再設定できませんでした。もう一度お試しください。',
} as const

export const verifyEmail = {
  title: 'メールアドレスの確認',
  verifying: 'メールアドレスを確認しています…',
  success: 'メールアドレスを確認しました。',
  invalidOrExpired: '確認リンクが無効か、有効期限が切れています。',
  resendCta: '確認メールを再送',
  resending: '送信しています…',
  resent: '確認メールを送信しました。受信トレイをご確認ください。',
  resendError: '確認メールを送信できませんでした。しばらくしてからお試しください。',
  toHome: 'ホームへ進む',
  toLogin: 'ログインへ進む',
  toAccountSettings: 'アカウント設定へ',
} as const

export const register = {
  title: 'はじめる',
  subtitle: 'レベル判定は次のステップで、話すだけで終わります。',
  name: 'お名前',
  email: 'メールアドレス',
  password: 'パスワード',
  passwordHint: '8文字以上。',
  language: '学ぶ言語',
  submit: 'アカウントを作成',
  pending: '作成しています…',
  hasAccount: 'すでにアカウントをお持ちですか？',
  toLogin: 'ログイン',
  error: 'アカウントを作成できませんでした。',
} as const

export const today = {
  greeting: 'こんにちは',
  continueLabel: '次のレッスン',
  weakSpotLabel: '弱点ピックアップ',
  weakSpotHint: '最近の会話で間違いが見られたため、優先しておすすめしています。',
  talkCta: '話しはじめる',
  roadmapLink: 'ロードマップを見る',
  allDone: '今日のレッスンは完了です',
  allDoneBody: '間隔反復のキューが空になるまで、復習で記憶を保ちましょう。',
  noRoadmap: 'ロードマップがまだありません',
  noRoadmapBody: 'レベル判定を受けると、いまの実力に合った順番でレッスンが並びます。',
  buildFirst: 'とりあえず作成する',
  takeAssessment: 'レベル判定を受ける',
  stats: {
    progress: '進捗',
    due: '復習待ち',
    level: 'CEFRレベル',
    minutes: '会話した時間',
  },
  streakHint: '毎日少しずつ話すのが、いちばん早い上達です。',
} as const

export const roadmap = {
  title: 'ロードマップ',
  subtitle: '現在のレベルに合わせて、基礎から順に並んでいます。',
  progress: '進捗',
  views: {
    graph: 'マップ',
    list: 'リスト',
  },
  graphHint: 'ノードを選んでレッスンを開けます。',
  mapAll: '全体を表示',
  mapFocus: '現在地にフォーカス',
  current: 'いまここ',
  available: '受講可能',
  needsPractice: '要復習',
  needsPracticeHint: '最近の会話でつまずいた文法です。',
  statusCompleted: '完了',
  statusLocked: 'ロック中',
  first: '最初のレッスン',
  startCard: 'このレッスンを話す',
  revisitCard: 'もう一度練習する',
  empty: 'ロードマップがまだありません',
  emptyBody:
    'レベル判定の結果をもとに、いまのCEFRバンドまでの文法を順番に並べます。判定を受けずに、現在の評価で作成することもできます。',
  build: 'ロードマップを作成',
  building: '作成しています…',
  buildError: 'ロードマップを作成できませんでした。',
  loadError: 'ロードマップを読み込めませんでした。',
  lockedHint: '前のレッスンを終えると開きます。',
  lockedNeeds: '前提レッスン: ',
  unlocksLabel: '次に開くレッスン: ',
  completedHint: '完了しました。',
  allDone: 'すべてのレッスンを終えました',
} as const

export const lesson = {
  title: 'レッスン',
  /**
   * Mode label shown on the lesson screen. The two conversation modes have
   * different AI behaviour (a lesson corrects during the conversation, a
   * scenario never does), so each screen names its mode rather than leaving the
   * difference to be read as instability.
   */
  mode: '文法チェックつき',
  picker: 'レッスンを選ぶ',
  topic: 'お題',
  cheatSheet: 'ヒント',
  cheatSheetOpen: 'ヒントを見る',
  cheatSheetClose: 'ヒントを閉じる',
  start: '会話を始める',
  connecting: '接続しています…',
  requesting: '部屋を準備しています…',
  requestingBody: 'サーバーが会話ルームとAI講師を準備しています。',
  connectingBody: '音声を接続し、マイクの使用を確認しています…',
  finish: '会話を終了',
  finishing: '終了しています…',
  elapsed: '経過',
  agentWaiting: 'AI講師を待っています…',
  agentHere: 'AI講師が参加中',
  stateIdle: 'タップして始めます',
  stateListening: '聞いています',
  stateSpeaking: '話しています',
  stateThinking: '考えています',
  stateError: '接続に問題があります',
  hintListening: '英語で話しかけてみましょう。途中で止まっても大丈夫です。',
  hintSpeaking: 'AI講師の英語を聞き取ってみましょう。',
  hintThinking: '返事を考えています。少し待ってください。',
  micNote: 'マイクに向かって、英語で話してください。',
  endedTitle: '会話を終えました',
  endedBody: '発話の分析が終わると、間違いの振り返りが届きます。',
  seeFeedback: '振り返りを見る',
  again: 'もう一度話す',
  noCards: '話せるレッスンがありません',
  noCardsBody: 'ロードマップで「いまここ」のレッスンを開いてください。',
  loadError: 'レッスンを読み込めませんでした。',
  notFound: 'レッスンが見つかりません',
  notFoundBody: 'このレッスンはロードマップにないか、別のアカウントのものです。',
  notReady: 'このレッスンはまだ開いていません',
  notReadyBody: 'ロードマップを順番に進めると開きます。',
  notReadyCompleted: 'このレッスンは完了済みです。',
  fleetBusy: 'AI講師が混み合っています。少し待ってから、もう一度お試しください。',
} as const

/**
 * 会話 — the topic picker and the scenario mode.
 *
 * Kept separate from `lesson` on purpose: this is free practice, not a grammar
 * lesson. Its screens never complete a lesson card and never show a cheat
 * sheet; the scenario's own useful phrases are the only support on screen.
 */
export const talk = {
  title: '会話',
  picker: 'お題を選ぶ',
  freeTalk: '自由に話す',
  freeTalkBody: '場面は決めずに、今週のことや仕事の話を、そのまま英語で話します。',
  setting: '場面',
  aiRole: '相手の役',
  yourRole: 'あなたの役',
  yourGoal: 'あなたの目的',
  usefulPhrases: '使える表現',
  modeHint: 'この会話は振り返りに記録されます。',
  register: {
    formal: 'フォーマル',
    casual: 'カジュアル',
    neutral: 'ニュートラル',
  },
  custom: {
    badge: 'カスタム',
    sectionTitle: '自分のお題',
    catalogueTitle: '定番のお題',
    openBuilder: '自分のお題を作る',
    openBuilderLead: '練習したい場面を短く書くと、AIが役柄・目的・使える表現を組み立てて確認を求めます。',
    editBuilder: '内容を調整する',
    stepPromptLabel: 'STEP 1 · シチュエーションを入力',
    stepPromptTitle: 'どんな場面を練習したいですか？',
    stepPromptHint: '日本語でも英語でも構いません。AIが英語のロールプレイ設定に整えます。',
    promptLabel: '練習したい場面・状況',
    promptPlaceholder: '例：カフェで注文と違う飲み物が届き、交換をお願いする / Return a faulty laptop at an electronics store',
    toneLabel: 'トーン',
    levelLabel: '難易度（CEFR）',
    draftCta: 'AIでお題を組み立てる',
    drafting: 'AIがお題を組み立てています…',
    regenerateCta: 'AIで作り直す',
    regenerating: 'AIが作り直しています…',
    stepConfirmLabel: 'STEP 2 · AIが整えた内容の確認',
    stepConfirmTitle: 'この設定で会話を始めますか？',
    stepConfirmLead: 'AIがプロンプトを膨らませました。場面・役柄・目的・表現は自由に書き換えられます（相手の裏の目的や展開は、本番の会話のために裏側で保持されます）。',
    fieldTitle: 'タイトル（英語）',
    fieldSummary: '概要（英語）',
    fieldSetting: '場面（Setting）',
    fieldAiRole: '相手の役（AI Role）',
    fieldLearnerRole: 'あなたの役（Your Role）',
    fieldLearnerGoal: 'あなたの目的（Your Goal）',
    fieldPhrases: '使える表現（3〜5個）',
    addPhrase: '表現を追加',
    removePhrase: '削除',
    confirmAndStart: '確定して会話を始める',
    confirmAndSave: '確定して一覧に保存',
    confirming: '保存しています…',
    cancel: 'キャンセル',
    deleteCta: '削除',
    deleting: '削除中…',
    draftError: 'AIがお題を組み立てられませんでした。内容を変えてもう一度お試しください。',
    confirmError: 'お題を保存できませんでした。入力内容を確認してください。',
    deleteError: 'お題を削除できませんでした。',
  },
  loading: 'お題を読み込んでいます…',
  loadError: 'お題を読み込めませんでした。',
  retry: '再試行',
  empty: '選べるお題がありません',
  emptyBody: '自由に話すか、ロードマップのレッスンから始めてください。',
  notFound: 'お題が見つかりません',
  notFoundBody: 'このお題は配信されていないか、コードが変わった可能性があります。お題の一覧から選び直してください。',
} as const

export const reflect = {
  title: '振り返り',
  loading: 'セッションを読み込んでいます…',
  notFound: 'セッションが見つかりません',
  notFoundBody: '指定されたIDのセッションがないか、別のアカウントのものです。',
  duration: '会話時間',
  analyzing: '発話を分析しています…',
  analyzingBody: '文字起こしと文法チェックを行っています。完了すると自動で表示されます。',
  checkNow: '今すぐ確認',
  noMistakes: '間違いは見つかりませんでした',
  noMistakesBody: 'この会話では文法上の誤りは検出されませんでした。その調子です。',
  analysisUnfinished: '分析が完了していません',
  analysisUnfinishedBody: 'この会話の分析はまだ終わっていません。「今すぐ確認」で再読み込みするか、しばらくしてからもう一度お試しください。',
  mistakesTitle: '見つかった間違い',
  youSaid: 'あなたの発話',
  correction: '正しい形',
  explanation: '解説',
  grammar: '文法',
  general: 'その他',
  noAnalysisYet: '分析結果はまだありません',
  backToTalk: 'もう一度話す',
  toReview: '復習に追加する',
  statusPending: '開始待ち',
  statusActive: '進行中',
  statusCompleted: '完了',
  statusFailed: 'エラー',
  statusAbandoned: '中断',
  loadError: 'セッションを読み込めませんでした。',
  failVoiceBusy: 'AI講師のワーカーが混み合っています。しばらくしてからお試しください。',
  failVoiceStart: '会話ルームを開始できませんでした。',
  failLivekitShutdown: '通話中に音声サーバーが再起動しました。',
  failLivekitRoom: '音声ルームを開けませんでした。',
  failStt: '音声認識に失敗しました。',
  failTts: '音声合成に失敗しました。',
  failLlm: '会話モデルでエラーが発生しました。',
  failAgent: 'AI講師の内部エラーです。',
  failUnknown: '予期しないエラーで終了しました。',
  failReason: '原因',
  /** Header label for a scenario session; a lesson shows the grammar category. */
  scenarioLabel: '会話',
  /**
   * Goal review (M6).
   *
   * This judges whether the scenario's task was completed, not the grammar of
   * individual utterances, so it gets its own heading and vocabulary instead of
   * borrowing the mistake copy.
   */
  goalTitle: '目標の達成',
  goalAchieved: '目標を達成しました',
  goalMissed: '目標に届きませんでした',
  goalMissedBody: 'つまずいた表現',
  goalMissedExpressions: '使えたはずの表現',
} as const

/**
 * The gate shown before the first voice session. It is separate from the
 * assessment consent copy: this one names the conversation use, not the level
 * judgement, so the learner is not asked to agree to something they did not
 * start.
 */
export const voiceConsent = {
  title: '音声の録音と処理について',
  intro: 'このレッスンでは音声を処理して、文字起こし・AI講師との会話・振り返りに使います。',
  points: [
    '音声は文字起こしとAI講師の応答のために処理されます。',
    '文字起こしと見つかった間違いは、振り返りと復習のために保存されます。',
    '会話はいつでも終了できます。',
  ],
  label: '音声の録音と処理に同意します。',
  cta: '同意して始める',
  cancel: 'キャンセル',
  error: '同意を記録できませんでした。もう一度お試しください。',
} as const

export const review = {
  title: '復習',
  subtitle: '会話で間違えた文法を、間隔をあけて思い出します。',
  due: '復習する項目',
  empty: '今日の復習はありません',
  emptyBody: '間隔反復のスケジュールに沿って、必要になるとここに現れます。',
  emptyCta: '会話をしに行く',
  /** The learner's own sentence from the conversation that produced this item. */
  yourUtterance: 'あなたの発話',
  recallPrompt: '正しく言うと？',
  reveal: '答えを見る',
  correction: '正しい形',
  explanation: '解説',
  /** Fallback when the mistake history behind an item is gone. */
  noMistakePrompt: 'このルールを使って、例文を一つ言ってみましょう。',
  scheduledPrefix: '次は',
  scheduledSuffix: '後にまた出ます。',
  doneTitle: '今日の復習は完了',
  doneBody: 'おつかれさまでした。次の復習はスケジュールに沿ってここに現れます。',
  doneCountSuffix: 'を復習しました。',
  nextAt: '次の復習',
  /**
   * Four grades, not SM-2's six explicit qualities: a learner grades how the
   * recall felt, not a number on a scale they have to learn. The mapping keeps
   * the algorithm's meaning — a fail resets the interval, a pass advances it.
   */
  grades: [
    { quality: 0, label: 'もう一度', desc: '思い出せなかった' },
    { quality: 3, label: '難しい', desc: '時間をかけて' },
    { quality: 4, label: 'できた', desc: 'すぐ出た' },
    { quality: 5, label: '完璧', desc: '迷わず言えた' },
  ],
  recurring: '繰り返している間違い',
  recurringBody: '会話でとくに多く間違えているルールです。',
  recurringIn: '直近のセッションで',
  totalMistakes: '合計',
  lastAt: '最後',
  loadError: '復習キューを読み込めませんでした。',
  submitError: '復習の結果を保存できませんでした。',
} as const

export const level = {
  title: 'レベル判定',
  subtitle: '1分ほど英語で話してください。CEFRレベルを判定し、ロードマップを組み直します。',
  consentTitle: '録音の前に',
  consentPoints: [
    '録音は安全なストレージに直接送られ、文字起こしの直後に削除されます。',
    '残るのは文字起こしと評価だけです。ロードマップに必要なためです。',
    '録音はいつでも中断できます。送信されなければ何も残りません。',
  ],
  consentLabel: 'レベル判定のための録音と音声処理に同意します。',
  consentCta: '同意して進む',
  readyTitle: '自己紹介を話してください',
  readyBody:
    '仕事や趣味、先週末にしたこと、今年の目標など。内容より、英語で話し続けることが大切です。',
  start: '録音を開始',
  recording: '録音中',
  recordingBody: '1分以上話すと、判定の精度が上がります。',
  stop: '停止して送信',
  uploading: 'アップロードしています…',
  waiting: '文字起こしと評価をしています。通常1分ほどです。',
  waitingFailed: '録音を解析できませんでした。もう一度録音するか、状態を確認してください。',
  checkNow: '状態を確認',
  recordAgain: '録音し直す',
  doneTitle: '判定が完了しました',
  doneLevel: '現在のレベル',
  doneBody: 'ロードマップを新しいレベルで組み直しました。',
  openRoadmap: 'ロードマップを開く',
  /**
   * 発音の目安 — the clarity section.
   *
   * The wording carries the honesty the feature depends on: this is how much of
   * the recording the recogniser was sure about, not a grade of the learner's
   * pronunciation. A learner who reads it as a phoneme score would be reading a
   * number the measurement cannot support, so the caveat is shown with the
   * numbers rather than hidden behind a disclosure.
   */
  pronunciationTitle: '発音の目安',
  pronunciationScore: (percent: number) => `はっきり聞き取れた語 ${percent}%`,
  pronunciationThinSample: '録音が短いため、全体の目安は出していません。',
  pronunciationBands: {
    clear: 'とてもはっきり',
    mostly_clear: 'おおむねはっきり',
    uneven: 'ところどころ不明瞭',
    effortful: '聞き取りにくい語が多め',
  },
  pronunciationWordsTitle: '聞き取りにくかった語',
  pronunciationConfidence: (percent: number) => `認識の確信度 ${percent}%`,
  pronunciationCaveat:
    'これは音声認識がどれだけ確信をもてたかの目安です。マイクの位置・周囲の音・なまりでも下がるため、発音そのものの点数ではありません。',
  micDenied: 'マイクを使用できません。ブラウザの設定で許可してください。',
  recorderFailed: 'このブラウザで録音を開始できませんでした。',
  emptyRecording: '録音が空でした。もう一度お試しください。',
  sendFailed: '録音を送信できませんでした。',
  sessionExpired: 'セッションが切れました。もう一度ログインしてください。',
} as const

/**
 * 設定 — the hub and its four sub-screens.
 *
 * `rows` are the hub's navigation entries, `sections` their group headings.
 * Account, appearance and data each have their own page, so the strings are
 * grouped the same way; a key that only the hub uses says so in its name.
 */
export const settings = {
  title: '設定',
  subtitle: 'アカウント、プラン、表示、データの扱い。',
  backToSettings: '設定に戻る',

  sections: {
    plan: 'プラン',
    appearance: '表示',
    data: 'データとプライバシー',
    session: 'セッション',
  },

  rows: {
    editProfile: 'プロフィールを編集',
    changePassword: 'パスワードを変更',
    plan: 'プランと利用状況',
    theme: 'テーマ',
    voiceConsent: '音声録音の同意',
    deleteAccount: 'アカウントの削除',
  },

  passwordSet: '設定済み',
  planSummary: (plan: string, remaining: number) => `${plan}・残り${remaining}分`,

  accountTitle: 'アカウント',
  accountLead: 'プロフィール、学習の設定、パスワード。',
  profileSection: 'プロフィール',
  name: 'お名前',
  nameLabel: 'お名前・ニックネーム',
  nameHint: 'ホーム画面の挨拶などに表示されます。',
  email: 'メールアドレス',
  emailHint: 'ログインとお知らせの宛先に使います。',
  emailVerified: '確認済み',
  emailUnverified: '未確認',
  resendVerification: '確認メールを再送',
  resendingVerification: '送信しています…',
  verificationSent: '確認メールを送信しました。受信トレイをご確認ください。',
  verificationSendError: '確認メールを送信できませんでした。しばらくしてからお試しください。',
  saveProfile: 'プロフィールを保存',
  savingProfile: '保存しています…',
  profileSaved: 'プロフィールを更新しました。',
  profileError: 'プロフィールを更新できませんでした。',
  learningSection: '学習',
  language: '学習中の言語',
  languageHint: '学習する言語は、アカウント作成時に決まります。',
  levelLabel: 'CEFRレベル',
  levelHint: '会話の難易度とロードマップは、このレベルに合わせて作られます。',
  retakeLevel: '判定を受け直す',
  passwordSection: 'パスワード',
  passwordHint: '新しいパスワードは8文字以上で設定してください。',
  currentPassword: '現在のパスワード',
  currentPasswordPlaceholder: '現在のパスワードを入力',
  newPassword: '新しいパスワード',
  newPasswordPlaceholder: '新しいパスワード（8文字以上）',
  confirmNewPassword: '新しいパスワード（確認）',
  confirmNewPasswordPlaceholder: '新しいパスワードをもう一度入力',
  passwordMismatchConfirm: '新しいパスワードと確認用パスワードが一致しません。',
  updatePasswordCta: 'パスワードを変更',
  updatingPassword: '変更しています…',
  passwordUpdated: 'パスワードを変更しました。',
  passwordUpdateError: 'パスワードを変更できませんでした。',

  appearanceTitle: '表示',
  appearanceLead: '画面の明るさを選べます。設定はこの端末に保存されます。',
  themeSection: 'テーマ',
  themeHint: 'ダークテーマは、夜の会話練習でも目が疲れにくい設定です。',
  themeDarkHint: '夜の練習でもまぶしくない',
  themeLightHint: '明るい場所でも読みやすい',
  themeSelected: '選択中',

  privacyTitle: 'データとプライバシー',
  privacyLead: '録音の同意と、保存されるデータについて。',
  consentSection: '音声録音の同意',
  consentBody: '音声は、文字起こし・AI講師との会話・振り返りのために処理されます。',
  consentDate: (date: string) => `同意を記録した日: ${date}`,
  consentMissingBody: '会話練習とレベル判定には、音声の録音と処理への同意が必要です。',
  voiceConsentYes: '記録あり',
  voiceConsentNo: '未記録',
  grantConsent: '同意を記録する',
  grantingConsent: '記録中…',
  privacyPolicyLink: 'プライバシーポリシーを読む',
  danger: 'アカウントの削除',
  dangerBody:
    'この操作は取り消せません。プロフィール、ロードマップ、会話履歴、間違い、録音がすべて削除されます。',
  passwordPlaceholder: 'パスワードを入力',
  confirmLabel: '確認のため',
  confirmSuffix: 'と入力してください',
  deleteCta: 'アカウントを削除',
  deleting: '削除しています…',
  deleteError: 'アカウントを削除できませんでした。しばらくしてからお試しください。',

  logout: 'ログアウト',
  loggingOut: 'ログアウトしています…',
  logoutHint: '次に使うときは、もう一度ログインしてください。',
} as const


/**
 * The pricing screen. Plan names and descriptions are keyed by the backend's
 * stable plan code (`entry`, `standard`, `pro`); the API sends money and
 * minutes, never Japanese.
 */
export const pricing = {
  eyebrow: 'PRICING',
  intervalLabel: '請求サイクル',
  title: '料金プラン',
  lead: '話した時間だけ、続けられる。まずは無料の15分から。',
  monthly: '月払い',
  yearly: '年払い',
  yearlyNote: '2か月分お得',
  perMonth: '月',
  perYear: '年',
  monthlyEquivalent: (yen: number) => `月あたり ¥${yen.toLocaleString('ja-JP')}`,
  freeTitle: 'Free',
  freeBody: 'クレジットカードなしで15分。レベル判定と最初の会話に。',
  freeCta: '無料で始める',
  freeAllowance: '15分 / 合計',
  minutesPerMonth: (minutes: number) => `${minutes}分 / 月`,
  choose: 'このプランにする',
  loginToChoose: 'ログインして申し込む',
  current: '現在のプラン',
  taxNote: '表示価格は税込です。いつでも解約できます。',
  loginPrompt: 'すでにアカウントをお持ちの方は',
  loginLink: 'ログイン',
  checkoutError: 'お支払いの画面を開けませんでした。しばらくしてからお試しください。',
  checkoutCancelled: 'お支払いはキャンセルされました。プランを選び直せます。',
  alreadySubscribedError: 'すでにプランをご契約中です。設定画面からプランを変更してください。',
  plans: {
    entry: {
      name: 'Entry',
      tagline: '毎日の会話に',
      description: '1日5分の会話練習。まずは習慣づくりから。',
    },
    standard: {
      name: 'Standard',
      tagline: 'しっかり話す',
      description: '1日10分。レッスンとロールプレイを往復する。',
    },
    pro: {
      name: 'Pro',
      tagline: 'とことん話す',
      description: '1日20分以上。試験前や出張前に集中して。',
    },
  },
} as const

/** The billing block on 設定. */
export const billing = {
  title: 'プランと利用状況',
  lead: '話した時間と、次回の更新。',
  plan: '現在のプラン',
  freePlan: '無料プラン',
  unknownPlan: '有料プラン',
  freeNote: '無料の15分は、レベル判定と最初の会話に使えます。',
  minutesUsed: (used: number, included: number) => `${used} / ${included}分`,
  remainingLabel: '残り',
  usageLabel: '今月の利用',
  usageLifetime: 'これまでの利用',
  minutesUnit: '分',
  statusActive: '有効',
  statusTrialing: 'トライアル中',
  statusPastDue: 'お支払い待ち',
  statusUnpaid: 'お支払い未完了',
  statusCanceled: '解約済み',
  resetsLabel: '次回リセット',
  currentPeriodEnd: '次回更新',
  cancelAtPeriodEnd: '期間終了後に解約されます',
  manage: '支払い・解約を管理',
  change: 'プランを変更',
  checkoutSuccess: 'お支払いが完了しました。反映まで少し時間がかかることがあります。',
  pastDue: 'お支払いに失敗しています。支払い方法を確認してください。',
  loadError: 'プラン情報を読み込めませんでした。',
  portalError: '支払い画面を開けませんでした。しばらくしてからお試しください。',
  notConfigured: 'このプランは現在お申し込みいただけません。',
  providerError: '決済画面を開けませんでした。しばらくしてからお試しください。',
} as const

/** The dialog that replaces a refused session with a way to keep talking. */
export const paywall = {
  title: '会話時間を使い切りました',
  freeBody: '無料の15分を使い切りました。プランを選ぶと、ここから続けられます。',
  paidBody: (used: number, included: number) => `今月の${included}分を使い切りました（${used}分使用）。`,
  resets: (date: string) => `次回は${date}にリセットされます。`,
  inactiveTitle: 'お支払いを確認してください',
  inactiveBody: 'サブスクリプションのお支払いが完了していません。支払い方法を更新すると会話を再開できます。',
  ctaPlans: 'プランを見る',
  ctaBilling: '支払い方法を確認',
  close: '閉じる',
} as const

export const landing = {
  nav: {
    story: 'ストーリー',
    method: 'メソッド',
    interactive: '体験する',
    privacy: 'プライバシー',
    login: 'ログイン',
    register: '無料で始める',
  },
  hero: {
    eyebrow: 'AI VOICE ENGLISH COACH',
    titleLine1: '読む英語から、',
    titleLine2: '話す英語へ。',
    lead: 'Kirie（キリエ）は、あなたの声を聞き、弱点を見抜き、今の実力にぴったりの会話を24時間いつでも相手するAI英語コーチです。',
    primaryCta: '無料で始める — 1分でレベル判定',
    secondaryCta: 'ログインして再開',
    scrollHint: 'スクロールして体験を再生',
    autoplayStart: 'ストーリーを自動再生',
    autoplayStop: '自動再生を停止',
  },
  chapters: [
    {
      id: 'prologue',
      index: '01',
      tag: 'PROLOGUE',
      shortTitle: '声の覚醒',
      title: 'テキストを閉じて、声に出す。',
      body: '単語帳を何周しても、いざ目の前に人が立つと言葉が出ない。必要なのは「知識の暗記」ではなく、自分の声で文を組み立てる圧倒的な場数です。',
      caption: 'Kirieの光のオーブは、あなたの声の抑揚とリズムにリアルタイムで呼応します。',
    },
    {
      id: 'diagnose',
      index: '02',
      tag: 'STEP 01 · DIAGNOSE',
      shortTitle: '1分間の声診断',
      title: '選択問題はゼロ。1分間、自由に話すだけ。',
      body: '仕事のこと、週末の出来事、趣味の話。1分間の自由発話から、語彙の幅・文法の正確さ・発音の明瞭度をAIが同時に解析し、CEFRレベル（A1〜C2）を即座に判定します。',
      caption: '音声認識の確信度をもとに、聞き取りにくかった単語まで特定します。',
    },
    {
      id: 'roadmap',
      index: '03',
      tag: 'STEP 02 · ROADMAP',
      shortTitle: '専用ロードマップ',
      title: 'いまのあなたに必要な文法だけが、一本の道になる。',
      body: '全員に同じ教材は配りません。判定されたレベルと苦手な文法構造から、最短で「話せる」ようになる順序でレッスンカードを自動編成します。',
      caption: '前のレッスンを声に出してクリアすると、次のステップがアンロックされます。',
    },
    {
      id: 'converse',
      index: '04',
      tag: 'STEP 03 · CONVERSE',
      shortTitle: 'AIと実践会話',
      title: '予約も、気まずさもいらない。深夜でも即座に会話が始まる。',
      body: 'その日の文法テーマを使って、AI講師とマンツーマンの音声セッション。詰まっても急かされず、手元のヒント（チートシート）を見ながら自分のペースで話せます。',
      caption: 'WebRTC低遅延ストリームにより、自然な間合いで会話が弾みます。',
    },
    {
      id: 'reflect',
      index: '05',
      tag: 'STEP 04 · REFLECT & RETAIN',
      shortTitle: '振り返りと定着',
      title: '言いっぱなしで終わらない。すべての間違いが資産になる。',
      body: '会話が終わると、あなたの発話から文法ミスを自動で抽出し「なぜ違うのか」「どう言えば自然か」を解説。さらに間隔反復（SM-2）が忘却曲線に合わせて復習を出題します。',
      caption: '「会話で間違える → 振り返る → 忘れかけた頃に思い出す」の完全なループ。',
    },
  ],
  stage: {
    liveIndicator: 'SCROLL CINEMA · INTERACTIVE PREVIEW',
    userTranscript: '“Last weekend I went to a small coffee shop in Shibuya and talked with the barista in English...”',
    detectedLevel: 'B1 Intermediate',
    clarityLabel: 'はっきり聞き取れた語 94%',
    uncertainWords: [
      { word: 'particularly', confidence: 68 },
      { word: 'atmosphere', confidence: 74 },
    ],
    roadmapItems: [
      { index: 1, category: 'Tenses', title: 'Present Perfect vs. Past Simple', status: 'completed', prompt: null },
      { index: 2, category: 'Modals', title: 'Polite Requests & Speculation', status: 'completed', prompt: null },
      { index: 3, category: 'Conditionals', title: 'Second Conditional (Hypotheticals)', status: 'ready', prompt: 'Talk about what you would change in your daily routine if you had two extra hours every day.' },
      { index: 4, category: 'Clauses', title: 'Relative Clauses in Spoken Narrative', status: 'locked', prompt: null },
    ],
    dialogue: {
      aiPrompt: '“If you had two extra hours every day, how would you spend them?”',
      userReply: '“If I have more time, I will probably practice cooking and read novels.”',
      aiFollowup: '“Nice idea! Let’s refine that conditional together after our chat.”',
    },
    reflection: {
      youSaid: 'If I have more time, I will probably practice cooking.',
      correction: 'If I had more time, I would probably practice cooking.',
      explanation: '現在の事実に反する仮定（「もし今もっと時間があったら」）を話すときは、Second Conditional（if + 過去形, would + 動詞の原形）を使うと自然な響きになります。',
      schedule: [
        { label: '初回', interval: '今日', active: false },
        { label: '1回目', interval: '1日後', active: false },
        { label: '2回目', interval: '4日後', active: true },
        { label: '3回目', interval: '12日後', active: false },
        { label: '定着', interval: '30日後', active: false },
      ],
    },
  },
  pillars: {
    eyebrow: 'WHY KIRIE WORKS',
    title: '「勉強しているのに話せない」を終わらせる、3つの設計。',
    subtitle: '知識のインプットと実践のアウトプットの間にあった深い溝を、音声AIと認知科学のループで埋めます。',
    items: [
      {
        num: '01',
        title: '評価される怖さがない、完全なプライベート空間',
        body: '対人の英会話レッスンでは「間違えたら恥ずかしい」という緊張が発話量を減らします。Kirieなら何度言い直しても、沈黙しても大丈夫。心理的安全性が発話量を最大化します。',
        metric: '3.4×',
        metricLabel: '1セッションあたりの平均発話量（対人レッスン比）',
      },
      {
        num: '02',
        title: '会話を止めない、事後精密フィードバック',
        body: '話している最中に細かく訂正されると、話すリズムが崩れます。Kirieは会話中は自然な対話に集中し、終了後に全発話を解析して「あなたの言葉」をベースにした改善案を提示します。',
        metric: '100%',
        metricLabel: 'すべての発話を文法・語彙・明瞭度で自動解析',
      },
      {
        num: '03',
        title: '自分の間違いから作られる、間隔反復キュー',
        body: '他人が作った例文ではなく、あなたが実際の会話で間違えた文法ルールこそが最も価値のある教材です。SM-2アルゴリズムが忘却の直前に復習を促し、長期記憶へ定着させます。',
        metric: 'SM-2',
        metricLabel: '記憶の減衰曲線に同期したアルゴリズム復習',
      },
    ],
  },
  playground: {
    eyebrow: 'INTERACTIVE ORB ENGINE',
    title: '生きているような、光の対話インターフェース。',
    subtitle: 'Kirieの象徴であるオーブは、単なるアニメーションではありません。音声エネルギーとAIの思考状態を60fpsのCanvas光のレイヤーで表現します。下のボタンを押して反応を試してみてください。',
    energyLabel: '声のエネルギー（音量・抑揚）',
    states: [
      { state: 'idle' as const, label: '待機中', desc: '静かな呼吸のリズムで、あなたが話し始めるのを待っています。' },
      { state: 'listening' as const, label: '聞いています', desc: 'あなたの声の波形と音量に呼応し、光の波紋（リップル）が広がります。' },
      { state: 'thinking' as const, label: '考えています', desc: '文脈と文法を解析しながら、軌道を回る光のトレーサーが思考を示します。' },
      { state: 'speaking' as const, label: '話しています', desc: 'AI講師の音声ストリームに合わせて、立体的な光が豊かに脈動します。' },
    ],
  },
  privacy: {
    eyebrow: 'PRIVACY BY DESIGN',
    title: 'あなたの声は、評価のためだけに使われ、即座に消去されます。',
    body: 'レベル判定の録音データは暗号化されたストレージへ直接送信され、文字起こしと評価が完了した直後に自動削除されます。また、設定画面からいつでも全履歴・アカウントを完全削除できます。',
    badges: [
      '録音データの即時自動削除',
      '明示的な音声処理同意ゲート',
      'ワンクリックで全データ完全消去',
    ],
    policyLink: 'プライバシーポリシー全文を見る',
  },
  cta: {
    eyebrow: 'START SPEAKING TODAY',
    title: '最初の1分間で、あなたの現在地がわかります。',
    subtitle: 'クレジットカード登録は不要。マイクに向かって1分間話すだけで、あなた専用の英語ロードマップが今日から始まります。',
    primary: 'アカウントを作成して始める',
    secondary: 'すでにアカウントをお持ちの方はこちら',
  },
} as const

export const legal = {
  links: {
    privacy: 'プライバシーポリシー',
    terms: '利用規約',
    tokushoho: '特定商取引法に基づく表記',
    pricing: '料金プラン',
    backHome: 'ホームへ戻る',
  },
  privacy: {
    eyebrow: 'PRIVACY POLICY',
    title: 'プライバシーポリシー',
    lead: 'Kirie（キリエ）は、学習に必要な最小限のデータのみを扱い、音声データの即時消去と透明な保持期間を徹底しています。',
    updatedAt: '最終改定日: 2026年11月11日',
    sections: [
      {
        title: '1. 取得する情報と利用目的',
        body: '当サービスは、AIによる英会話練習・CEFRレベル判定・文法ロードマップ作成・間隔反復（SM-2）復習の提供に必要な範囲でのみ情報を取得します。',
        items: [
          'アカウント情報：お名前、メールアドレス、パスワードハッシュ、学習対象言語、タイムゾーン、現在のCEFRレベル（本人確認および学習進捗の保持のため）',
          '音声処理の同意記録：録音および音声解析に対する明示的な同意日時（同意証跡の記録のため）',
          'レベル判定の録音データ：レベル判定時に録音された音声ファイル（文字起こしおよびCEFR評価のため）',
          '会話セッションの文字起こし・文法フィードバック：発話テキスト、文法ミスの指摘・解説、および応答遅延の計測値（振り返りと復習スケジュール算出のため）',
          '決済関連識別子：Stripe顧客ID、サブスクリプションID、契約プラン、利用期間（利用時間枠の管理のため）',
        ],
      },
      {
        title: '2. 音声データと文字起こしの保持期間・自動消去',
        body: '音声および会話ログは、教育上の目的を果たした後に自動で消去される設計になっています。',
        items: [
          'レベル判定の音声ファイル：暗号化されたストレージへ直接アップロードされ、文字起こしとCEFR判定が完了した直後（通常数分以内）に自動削除されます。万一処理が中断された残存ファイルも、毎日実行されるクリーンアップにより7日以内に完全消去されます。',
          '会話・判定の文字起こし（トランスクリプト）：作成から90日が経過すると、日次バッチ処理により自動的に消去されます。学習の記録（CEFR判定結果、抽出された文法ミス、復習カード）のみが残ります。',
          '同意の取得：初回のレベル判定または音声セッションの前に明示的な同意画面を表示し、同意が記録されるまでサーバーは音声処理を一切受け付けません。',
        ],
      },
      {
        title: '3. クレジットカード・決済情報の取り扱い',
        body: 'クレジットカード番号などの決済情報は当サービスのサーバーを通過・保存せず、決済代行会社であるStripe, Inc.が提供するホスト型画面（Stripe Checkoutおよびカスタマーポータル）にて直接処理されます。',
        items: [],
      },
      {
        title: '4. 外部サービス（業務委託先）の利用',
        body: 'リアルタイムの音声対話および学習フィードバックを生成するため、以下の外部プロバイダに必要なデータを送信します。また、エラー監視（Sentry）では発話テキストやプロンプト等の個人情報を送信前にスクラビング（除去）しています。',
        items: [
          '音声認識（STT）：Deepgram',
          '音声合成（TTS）：Cartesia / OpenAI',
          '対話生成および文法・レベル解析（LLM）：Groq / Cerebras / OpenAI / DeepSeek',
          'リアルタイム音声通信基盤（WebRTC）：LiveKit',
          '決済処理：Stripe',
        ],
      },
      {
        title: '5. アカウントと全データの自己削除',
        body: 'ログイン後の「設定」画面にある「アカウントの削除」から、いつでもご自身の操作でアカウントを完全に削除できます。削除を実行すると、有効なStripeサブスクリプションが即座に解約されたうえで、プロフィール・ロードマップ・会話履歴・文法ミス・録音データがすべてデータベースおよびストレージから消去されます（データベースの自動バックアップスナップショットに含まれる履歴は、バックアップ保持期間の満了に伴い順次消滅します）。',
        items: [],
      },
    ],
  },
  terms: {
    eyebrow: 'TERMS OF SERVICE',
    title: '利用規約',
    lead: 'Kirie（以下「当サービス」）をご利用いただく際の条件を定めるものです。',
    updatedAt: '最終改定日: 2026年11月11日',
    sections: [
      {
        title: '1. サービスの内容',
        body: '当サービスは、AI音声コーチとのリアルタイム英会話練習、音声によるCEFRレベル判定、文法ロードマップ、および間隔反復（SM-2）による復習機能を提供する語学学習サービスです。AIによる判定やフィードバックは学習支援を目的としたものであり、特定の試験結果や正確性を保証するものではありません。',
        items: [],
      },
      {
        title: '2. アカウント登録と管理',
        body: '利用者は正確な情報をもってアカウントを登録し、ご自身の責任においてパスワードおよびセッションを管理するものとします。1つのアカウントを複数人で共有することはできません。',
        items: [],
      },
      {
        title: '3. 無料利用枠と有料サブスクリプション',
        body: '新規アカウントには、クレジットカード登録不要で利用できる無料会話枠（合計15分）が付与されます。有料プラン（Entry / Standard / Pro）をご契約いただくと、プランに応じた月間会話分数が付与されます。',
        items: [
          '月間利用枠のリセット：月払い・年払いのいずれにおいても、月間の会話可能時間はご契約日を起点として1か月ごとにリセットされます。未使用分の翌月への繰り越しはありません。',
          '1日あたりのセッション上限：システム保護および健全な運用のため、1日あたりの音声セッション開始回数に上限（原則10回/日）を設けています。',
        ],
      },
      {
        title: '4. 解約・プラン変更・返金について',
        body: '有料サブスクリプションは、「設定」画面の「支払い・解約を管理」（Stripeカスタマーポータル）からいつでも解約またはプラン変更が可能です。',
        items: [
          '解約の効力：解約手続きを行うと次回以降の自動更新が停止され、現在の請求期間（月払いまたは年払い）の終了日までは引き続きプランをご利用いただけます。',
          '返金ポリシー：デジタルコンテンツおよびクラウド音声処理サービスの性質上、決済完了後の期間途中の解約による日割り・月割りでの返金は行っておりません（法令により返金が義務付けられる場合を除きます）。',
        ],
      },
      {
        title: '5. 禁止事項',
        body: '利用者は、当サービスの利用にあたり以下の行為をしてはなりません。違反が確認された場合、事前の通知なく利用制限またはアカウント停止を行うことがあります。',
        items: [
          '法令または公序良俗に違反する行為、違法・有害なコンテンツを音声またはテキストで送信する行為',
          '自動化ツールやスクリプト等による大量リクエスト、リバースエンジニアリング、またはサーバー・音声基盤に過度な負荷をかける行為',
          '第三者のになりすまし、または不正な決済手段を使用する行為',
        ],
      },
      {
        title: '6. 免責事項・サービスの変更',
        body: '当サービスは、通信回線や外部AIプロバイダ・音声通信基盤の障害、メンテナンス等により一時的に中断される場合があります。法令で認められる最大限の範囲において、当サービスの利用または利用不能により生じた損害について、運営者の故意または重過失による場合を除き、過去1か月間に利用者が当サービスに支払った利用料金を上限として責任を負うものとします。',
        items: [],
      },
    ],
  },
  tokushoho: {
    eyebrow: 'SPECIFIED COMMERCIAL TRANSACTIONS ACT',
    title: '特定商取引法に基づく表記',
    lead: '特定商取引法第11条に基づき、以下のとおり表示いたします。',
    disclosureFallback: '請求があったら遅滞なく開示します',
    operatorFallback: 'Kirie 運営事務局（個人事業主・請求があったら遅滞なく開示します）',
    emailFallback: '請求があったら遅滞なく開示します（設定画面またはサポート窓口よりお問い合わせください）',
    rows: {
      operator: '販売事業者・運営責任者',
      address: '所在地',
      phone: '電話番号',
      email: 'メールアドレス',
      price: '販売価格',
      priceValue: '各プランページ（/pricing）に表示された税込価格（Entry: 月額799円・年額7,990円 / Standard: 月額1,480円・年額14,800円 / Pro: 月額2,980円・年額29,800円）',
      additionalFees: '商品代金以外の必要料金',
      additionalFeesValue: 'インターネット接続料金および通信料金はお客様のご負担となります。',
      paymentMethod: 'お支払方法',
      paymentMethodValue: 'クレジットカード決済（Stripe）',
      paymentTiming: 'お支払時期',
      paymentTimingValue: '初回お申し込み時および毎月の更新日（年払いの場合は毎年の更新日）に決済されます。',
      deliveryTiming: '商品の引渡時期（役務の提供時期）',
      deliveryTimingValue: '決済完了後、ただちにご利用いただけます。',
      cancellation: '解約・返品・交換について',
      cancellationValue: 'デジタルサービスの性質上、決済完了後の返品・返金はお受けしておりません。「設定」画面の支払い管理ポータルよりいつでも解約手続きが可能であり、解約後は次回更新日以降の請求が停止され、現在の契約期間満了まで引き続きご利用いただけます。',
      environment: '動作環境',
      environmentValue: 'マイク入力およびWebRTCに対応した最新のWebブラウザ（Chrome、Safari、Edge、Firefox）',
    },
  },
} as const
