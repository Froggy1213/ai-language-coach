import type { CefrLevel, CheatSheet, LessonCardStatus } from '~/types/graphql'

export const CEFR_BANDS: readonly CefrLevel[] = ['A1', 'A2', 'B1', 'B2', 'C1'] as const

export interface RoadmapGraphPrerequisite {
  code: string
  title: string
}

export interface RoadmapGraphGrammarPoint {
  id: string
  code: string
  title: string
  category: string
  level?: CefrLevel | null
  prerequisites?: RoadmapGraphPrerequisite[] | null
}

export interface RoadmapGraphCard {
  id: string
  orderIndex: number
  status: LessonCardStatus
  practicePrompt: string
  recentMistakeCount?: number
  needsPractice?: boolean
  cheatSheet?: CheatSheet
  grammarPoint: RoadmapGraphGrammarPoint
}

export type GraphNodeState =
  | 'current'
  | 'needs-practice'
  | 'ready'
  | 'completed'
  | 'locked'

export interface GraphNode {
  id: string
  code: string
  title: string
  category: string
  level: CefrLevel
  orderIndex: number
  status: LessonCardStatus
  state: GraphNodeState
  recommended: boolean
  needsPractice: boolean
  recentMistakeCount: number
  practicePrompt: string
  prerequisites: RoadmapGraphPrerequisite[]
  unlocksCodes: string[]
  x: number
  y: number
  subRow: number
}

export interface GraphEdge {
  id: string
  from: string
  to: string
  fromBand: CefrLevel
  toBand: CefrLevel
  d: string
  completed: boolean
  unlocked: boolean
}

export interface GraphBand {
  level: CefrLevel
  y: number
  height: number
  subRows: number
  nodes: GraphNode[]
}

export interface RoadmapGraph {
  width: number
  height: number
  nodeRadius: number
  bands: GraphBand[]
  nodes: GraphNode[]
  edges: GraphEdge[]
}

export interface BuildGraphOptions {
  recommendedCardId?: string | null
  learnerLevel?: CefrLevel | null
  width?: number
  maxPerSubRow?: number
}

const BAND_RANK: Record<CefrLevel, number> = {
  A1: 1,
  A2: 2,
  B1: 3,
  B2: 4,
  C1: 5,
}

const DEFAULT_WIDTH = 960
const NODE_RADIUS = 22
const MAX_PER_SUB_ROW = 5
const BAND_TOP_PADDING = 54
const BAND_BOTTOM_PADDING = 56
const SUB_ROW_GAP = 108
const BAND_GAP = 16
const HORIZONTAL_PADDING = 96

/**
 * Ranks a CEFR level from 1 (A1) to 5 (C1).
 */
export function cefrRank(level?: CefrLevel | null): number {
  if (!level) {
    return 1
  }
  return BAND_RANK[level] ?? 1
}

/**
 * Picks the primary recommended card for the learner:
 * 1. An unlocked (`ready`) card flagged with `needsPractice` (weak spot from recent sessions).
 * 2. An unlocked (`ready`) card at or above the learner's current CEFR level, or the first `ready` card by `orderIndex`.
 * 3. When all cards are completed, the first non-locked `needsPractice` card so weak spots still surface for review.
 */
export function pickRecommendedCard<T extends RoadmapGraphCard>(
  cards: readonly T[],
  learnerLevel?: CefrLevel | null,
): T | null {
  if (!cards.length) {
    return null
  }

  const sorted = [...cards].sort((a, b) => a.orderIndex - b.orderIndex)

  const readyWeakSpot = sorted.find(
    (card) => card.status === 'ready' && Boolean(card.needsPractice),
  )
  if (readyWeakSpot) {
    return readyWeakSpot
  }

  if (learnerLevel) {
    const minRank = cefrRank(learnerLevel)
    const readyAtLevel = sorted.find(
      (card) =>
        card.status === 'ready'
        && cefrRank(card.grammarPoint.level) >= minRank,
    )
    if (readyAtLevel) {
      return readyAtLevel
    }
  }

  const firstReady = sorted.find((card) => card.status === 'ready')
  if (firstReady) {
    return firstReady
  }

  const reviewWeakSpot = sorted.find(
    (card) => card.status !== 'locked' && Boolean(card.needsPractice),
  )
  return reviewWeakSpot ?? null
}

/**
 * Splits a band's ordered cards into balanced sub-rows of at most `maxPerSubRow` items,
 * ensuring any prerequisite within the same CEFR band sits in an earlier sub-row
 * than its dependent whenever a band splits into multiple rows.
 */
function partitionIntoSubRows(
  cards: RoadmapGraphCard[],
  maxPerSubRow: number,
): RoadmapGraphCard[][] {
  if (cards.length <= maxPerSubRow) {
    return [cards]
  }

  const codesInBand = new Set(cards.map((c) => c.grammarPoint.code))
  const rowCount = Math.ceil(cards.length / maxPerSubRow)
  const targetFirstRowCount = Math.ceil(cards.length / rowCount)

  if (rowCount === 2) {
    const firstRow: RoadmapGraphCard[] = []
    const secondRow: RoadmapGraphCard[] = []
    const firstRowCodes = new Set<string>()

    for (const card of cards) {
      const prereqs = card.grammarPoint.prerequisites ?? []
      const dependsOnFirstRow = prereqs.some((p) => firstRowCodes.has(p.code))
      const hasInBandPrereq = prereqs.some((p) => codesInBand.has(p.code))

      if (
        firstRow.length < targetFirstRowCount
        && (!dependsOnFirstRow || !hasInBandPrereq)
      ) {
        firstRow.push(card)
        firstRowCodes.add(card.grammarPoint.code)
      } else {
        secondRow.push(card)
      }
    }

    // Balance if dependency heuristics pushed too many to the second row
    while (firstRow.length < targetFirstRowCount && secondRow.length > 0) {
      const moved = secondRow.shift()!
      firstRow.push(moved)
    }

    return [firstRow, secondRow]
  }

  const rows: RoadmapGraphCard[][] = []
  for (let i = 0; i < cards.length; i += maxPerSubRow) {
    rows.push(cards.slice(i, i + maxPerSubRow))
  }
  return rows
}

/**
 * Infers a fallback CEFR band from `orderIndex` only if `grammarPoint.level`
 * is missing on legacy or unmigrated fixtures.
 */
function resolveBandLevel(card: RoadmapGraphCard): CefrLevel {
  const explicit = card.grammarPoint.level
  if (explicit && CEFR_BANDS.includes(explicit)) {
    return explicit
  }

  if (card.orderIndex <= 8) return 'A1'
  if (card.orderIndex <= 17) return 'A2'
  if (card.orderIndex <= 26) return 'B1'
  if (card.orderIndex <= 33) return 'B2'
  return 'C1'
}

/**
 * Computes the visual node state from card status, recommendation, and mistake signals.
 */
export function resolveNodeState(
  card: RoadmapGraphCard,
   isRecommended: boolean,
): GraphNodeState {
  if (isRecommended) {
    return 'current'
  }

  const hasWeakSpot = Boolean(card.needsPractice)
    && (card.recentMistakeCount === undefined || card.recentMistakeCount > 0)

  if (hasWeakSpot && card.status !== 'locked') {
    return 'needs-practice'
  }

  return card.status
}

/**
 * Builds a smooth cubic Bezier SVG path string connecting `source` to `target`.
 */
function buildEdgePath(source: GraphNode, target: GraphNode, radius: number): string {
  const dx = target.x - source.x
  const dy = target.y - source.y

  if (Math.abs(dy) < 8) {
    // Nodes share the same horizontal sub-row: arc gently above the row
    const startX = source.x + (dx >= 0 ? radius * 0.7 : -radius * 0.7)
    const startY = source.y - radius * 0.7
    const endX = target.x - (dx >= 0 ? radius * 0.7 : -radius * 0.7)
    const endY = target.y - radius * 0.7
    const archHeight = Math.min(48, Math.max(28, Math.abs(dx) * 0.18))
    const midX = (startX + endX) / 2
    const ctrlY = startY - archHeight
    return `M ${round(startX)} ${round(startY)} Q ${round(midX)} ${round(ctrlY)} ${round(endX)} ${round(endY)}`
  }

  const downward = dy > 0
  const startX = source.x
  const startY = source.y + (downward ? radius : -radius)
  const endX = target.x
  const endY = target.y + (downward ? -radius : radius)
  const spanY = endY - startY
  const c1x = startX + dx * 0.08
  const c1y = startY + spanY * 0.46
  const c2x = endX - dx * 0.08
  const c2y = endY - spanY * 0.46

  return `M ${round(startX)} ${round(startY)} C ${round(c1x)} ${round(c1y)}, ${round(c2x)} ${round(c2y)}, ${round(endX)} ${round(endY)}`
}

function round(value: number): number {
  return Math.round(value * 10) / 10
}

/**
 * Pure, deterministic layout function converting roadmap lesson cards into
 * CEFR bands, positioned nodes, and cubic Bezier prerequisite edges.
 */
export function buildGraph(
  cards: readonly RoadmapGraphCard[],
  options: BuildGraphOptions = {},
): RoadmapGraph {
  const width = options.width ?? DEFAULT_WIDTH
  const maxPerSubRow = options.maxPerSubRow ?? MAX_PER_SUB_ROW

  const sortedCards = [...cards].sort((a, b) => a.orderIndex - b.orderIndex)
  const recommendedId =
    options.recommendedCardId !== undefined
      ? options.recommendedCardId
      : (pickRecommendedCard(sortedCards, options.learnerLevel)?.id ?? null)

  // Group cards by CEFR band
  const grouped = new Map<CefrLevel, RoadmapGraphCard[]>()
  for (const level of CEFR_BANDS) {
    grouped.set(level, [])
  }
  for (const card of sortedCards) {
    const level = resolveBandLevel(card)
    grouped.get(level)!.push(card)
  }

  // Build reverse adjacency map (prerequisite code -> dependent codes)
  const unlocksByCode = new Map<string, string[]>()
  for (const card of sortedCards) {
    const prereqs = card.grammarPoint.prerequisites ?? []
    for (const prereq of prereqs) {
      const list = unlocksByCode.get(prereq.code) ?? []
      list.push(card.grammarPoint.code)
      unlocksByCode.set(prereq.code, list)
    }
  }

  const bands: GraphBand[] = []
  const nodes: GraphNode[] = []
  const nodeByCode = new Map<string, GraphNode>()

  let currentY = 20
  const usableWidth = width - HORIZONTAL_PADDING * 2

  for (const level of CEFR_BANDS) {
    const bandCards = grouped.get(level) ?? []
    if (bandCards.length === 0) {
      continue
    }

    const subRows = partitionIntoSubRows(bandCards, maxPerSubRow)
    const bandHeight =
      BAND_TOP_PADDING
      + BAND_BOTTOM_PADDING
      + Math.max(0, subRows.length - 1) * SUB_ROW_GAP

    const bandNodes: GraphNode[] = []

    subRows.forEach((rowCards, subRowIndex) => {
      const rowY = currentY + BAND_TOP_PADDING + subRowIndex * SUB_ROW_GAP
      const count = rowCards.length
      // Spread nodes evenly and center them horizontally.
      // When a band has 2 sub-rows of identical count, offset the second sub-row slightly
      // so vertical edges from earlier bands do not pass directly through upper-row centres.
      const maxSpacing = 188
      const step = count > 1 ? Math.min(maxSpacing, usableWidth / (count - 1)) : 0
      const rowSpan = step * (count - 1)
      const stagger =
        subRows.length > 1 && subRows[0]!.length === count
          ? (subRowIndex % 2 === 1 ? 26 : -26)
          : 0
      const startX = (width - rowSpan) / 2 + stagger

      rowCards.forEach((card, colIndex) => {
        const x = round(count === 1 ? width / 2 : startX + colIndex * step)
        const y = round(rowY)
        const isRecommended = recommendedId !== null && card.id === recommendedId
        const needsPractice = Boolean(card.needsPractice)
        const recentMistakeCount = card.recentMistakeCount ?? (needsPractice ? 1 : 0)
        const state = resolveNodeState(card, isRecommended)

        const node: GraphNode = {
          id: card.id,
          code: card.grammarPoint.code,
          title: card.grammarPoint.title,
          category: card.grammarPoint.category,
          level,
          orderIndex: card.orderIndex,
          status: card.status,
          state,
          recommended: isRecommended,
          needsPractice,
          recentMistakeCount,
          practicePrompt: card.practicePrompt,
          prerequisites: [...(card.grammarPoint.prerequisites ?? [])],
          unlocksCodes: unlocksByCode.get(card.grammarPoint.code) ?? [],
          x,
          y,
          subRow: subRowIndex,
        }

        bandNodes.push(node)
        nodes.push(node)
        nodeByCode.set(node.code, node)
      })
    })

    bands.push({
      level,
      y: currentY,
      height: bandHeight,
      subRows: subRows.length,
      nodes: bandNodes,
    })

    currentY += bandHeight + BAND_GAP
  }

  const totalHeight = Math.max(240, currentY + 12)

  // Compute edges in deterministic order
  const edges: GraphEdge[] = []
  for (const target of nodes) {
    for (const prereq of target.prerequisites) {
      const source = nodeByCode.get(prereq.code)
      if (!source) {
        continue
      }

      edges.push({
        id: `${source.code}->${target.code}`,
        from: source.code,
        to: target.code,
        fromBand: source.level,
        toBand: target.level,
        d: buildEdgePath(source, target, NODE_RADIUS),
        completed: source.status === 'completed' && target.status === 'completed',
        unlocked: source.status === 'completed',
      })
    }
  }

  return {
    width,
    height: totalHeight,
    nodeRadius: NODE_RADIUS,
    bands,
    nodes,
    edges,
  }
}
