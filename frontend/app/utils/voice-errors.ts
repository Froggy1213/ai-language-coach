import type { CombinedError } from '@urql/vue'
import { talk } from '~/constants/strings'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'

/**
 * Maps a failed voice-token request to learner-facing copy.
 *
 * The backend separates the reasons a session cannot start into GraphQL error
 * codes. Fleet-busy reuses the shared talk copy; the rest are specific to
 * starting a conversation. The shared mapper still owns validation errors,
 * unknown codes and the final fallback.
 */
export function mapVoiceError(failure: CombinedError): string {
  return graphQLErrorMessageFor(failure, {
    codes: {
      VOICE_FLEET_BUSY: talk.fleetBusy,
      VOICE_START_FAILED: 'サーバーで会話セッションを開始できませんでした。しばらくしてからお試しください。',
      VOICE_DAILY_LIMIT_REACHED: '本日の会話セッションの上限に達しました。明日また試してください。',
      LESSON_CARD_NOT_FOUND: 'このレッスンはロードマップにありません。',
      LESSON_CARD_LOCKED: 'このレッスンはまだ開いていません。',
      REVIEW_ITEM_NOT_FOUND: '復習する項目が見つかりません。',
      GRAMMAR_POINT_NOT_FOUND: '文法項目が見つかりません。',
    },
    messages: {
      'This lesson card does not exist.': 'このレッスンはロードマップにありません。',
      'This lesson card is not ready to practise yet.': 'このレッスンはまだ練習できません。',
    },
    fallback: '会話セッションを開始できませんでした。',
  })
}
