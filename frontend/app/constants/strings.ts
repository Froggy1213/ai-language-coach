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
  loading: '読み込み中…',
  retry: 'もう一度試す',
  retryShort: '再試行',
  checking: '確認中…',
  refresh: '更新',
  refreshing: '更新中…',
  back: '戻る',
  backToRoadmap: 'ロードマップへ',
  cancel: 'キャンセル',
  close: '閉じる',
  save: '保存',
  start: '始める',
  finish: '終了する',
  empty: 'まだありません',
  unknown: '不明',
  logout: 'ログアウト',
  theme: 'テーマ',
  themeDark: 'ダーク',
  themeLight: 'ライト',
} as const

export const login = {
  title: 'おかえりなさい',
  subtitle: 'ロードマップの続きから再開できます。',
  email: 'メールアドレス',
  password: 'パスワード',
  submit: 'ログイン',
  pending: 'ログインしています…',
  noAccount: 'アカウントをお持ちでないですか？',
  toRegister: '新規登録',
  error: 'ログインできませんでした。',
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
  current: 'いまここ',
  first: '最初のレッスン',
  startCard: 'このレッスンを話す',
  empty: 'ロードマップがまだありません',
  emptyBody:
    'レベル判定の結果をもとに、いまのCEFRバンドまでの文法を順番に並べます。判定を受けずに、現在の評価で作成することもできます。',
  build: 'ロードマップを作成',
  building: '作成しています…',
  buildError: 'ロードマップを作成できませんでした。',
  loadError: 'ロードマップを読み込めませんでした。',
  lockedHint: '前のレッスンを終えると開きます。',
  completedHint: '完了しました。',
  allDone: 'すべてのレッスンを終えました',
} as const

export const talk = {
  title: '会話',
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
} as const

export const review = {
  title: '復習',
  subtitle: '会話で間違えた文法を、間隔をあけて思い出します。',
  due: '復習する項目',
  empty: '今日の復習はありません',
  emptyBody: '間隔反復のスケジュールに沿って、必要になるとここに現れます。',
  emptyCta: '会話をしに行く',
  prompt: 'このルールをどれくらい覚えていましたか？',
  scaleLow: 'まったく思い出せない',
  scaleMid: '時間をかければ',
  scaleHigh: '完璧',
  interval: '間隔',
  ease: '難易度係数',
  repetitions: '復習回数',
  nextAt: '次回',
  recurring: '繰り返している間違い',
  recurringBody: '会話でとくに多く間違えているルールです。',
  recurringIn: '直近のセッションで',
  totalMistakes: '合計',
  lastAt: '最後',
  loadError: '復習キューを読み込めませんでした。',
  submitError: '復習の結果を保存できませんでした。',
  grades: [
    { quality: 0, desc: 'まったく思い出せない' },
    { quality: 1, desc: 'ほとんど出てこない' },
    { quality: 2, desc: '時間をかければ' },
    { quality: 3, desc: '少し迷った' },
    { quality: 4, desc: 'すぐ出た' },
    { quality: 5, desc: '完璧' },
  ],
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

export const settings = {
  title: '設定',
  subtitle: 'アカウント、表示、データの扱い。',
  account: 'アカウント',
  name: 'お名前',
  email: 'メールアドレス',
  language: '学習中の言語',
  levelLabel: 'CEFRレベル',
  voiceConsent: '音声録音の同意',
  voiceConsentYes: '記録あり',
  voiceConsentNo: '未記録',
  appearance: '表示',
  themeHint: 'ダークテーマは、夜の会話練習でも目が疲れにくい設定です。',
  session: 'セッション',
  logout: 'ログアウト',
  loggingOut: 'ログアウトしています…',
  logoutHint: '次に使うときは、もう一度ログインしてください。',
  danger: 'アカウントの削除',
  dangerBody:
    'この操作は取り消せません。プロフィール、ロードマップ、会話履歴、間違い、録音がすべて削除されます。',
  currentPassword: '現在のパスワード',
  passwordPlaceholder: 'パスワードを入力',
  confirmLabel: '確認のため',
  confirmSuffix: 'と入力してください',
  deleteCta: 'アカウントを削除',
  deleting: '削除しています…',
  loadError: 'アカウント情報を読み込めませんでした。',
} as const

export const units = {
  min: '分',
  sec: '秒',
} as const
