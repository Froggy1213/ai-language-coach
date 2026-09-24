export type CefrLevel = 'A1' | 'A2' | 'B1' | 'B2' | 'C1'
export type LessonCardStatus = 'locked' | 'ready' | 'completed'
export type RoadmapStatus = 'active' | 'completed' | 'archived'
export type AssessmentStatus = 'processing' | 'done' | 'failed'

export interface CurrentUser {
  id: string
  name: string
  targetLanguage: string
  currentLevel: CefrLevel
}

export interface GrammarPoint {
  id: string
  code: string
  title: string
  category: string
}

export interface CheatSheet {
  rule: string
  formula: string
  examples: string[]
  pitfalls: string[]
}

export interface LessonCard {
  id: string
  orderIndex: number
  status: LessonCardStatus
  practicePrompt: string
  cheatSheet: CheatSheet
  grammarPoint: GrammarPoint
}

export interface Roadmap {
  id: string
  title: string
  status: RoadmapStatus
  lessonCards: LessonCard[]
}

export interface Assessment {
  id: string
  status: AssessmentStatus
  cefrLevel: CefrLevel | null
}

export interface PresignedUpload {
  uploadUrl: string
  fileUrl: string
  fields: Array<{ name: string; value: string }>
}
