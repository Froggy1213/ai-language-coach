<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { roadmap } from '~/constants/strings'
import {
  buildGraph,
  type GraphBand,
  type GraphEdge,
  type GraphNode,
  type RoadmapGraphCard,
} from '~/utils/roadmap-graph'

const props = withDefaults(
  defineProps<{
    cards: readonly RoadmapGraphCard[]
    recommendedCardId?: string | null
    /** When true (e.g. in `/dev/graph`), clicking a node selects it instead of navigating away. */
    previewOnly?: boolean
  }>(),
  {
    recommendedCardId: undefined,
    previewOnly: false,
  },
)

const emit = defineEmits<{
  select: [node: GraphNode]
}>()

const activeCode = ref<string | null>(null)
const focusMode = ref(false)

onMounted(() => {
  if (typeof window !== 'undefined' && window.innerWidth < 768) {
    focusMode.value = true
  }
})

const graph = computed(() =>
  buildGraph(props.cards, {
    recommendedCardId: props.recommendedCardId,
  }),
)

const nodeByCode = computed(() => {
  const map = new Map<string, GraphNode>()
  for (const node of graph.value.nodes) {
    map.set(node.code, node)
  }
  return map
})

const defaultInspectedNode = computed<GraphNode | null>(() => {
  if (!graph.value.nodes.length) {
    return null
  }
  if (activeCode.value) {
    const found = nodeByCode.value.get(activeCode.value)
    if (found) {
      return found
    }
  }
  return (
    graph.value.nodes.find((n) => n.recommended)
    ?? graph.value.nodes.find((n) => n.needsPractice && n.status !== 'locked')
    ?? graph.value.nodes.find((n) => n.status === 'ready')
    ?? graph.value.nodes[0]
    ?? null
  )
})

/**
 * Codes that belong to the "focus neighborhood" in mobile Focus mode:
 * the recommended node, any `needsPractice` nodes, any `ready` nodes,
 * plus their immediate prerequisites and immediate unlock targets.
 */
const focusNeighborhoodCodes = computed<Set<string>>(() => {
  const codes = new Set<string>()
  const seeds = graph.value.nodes.filter(
    (node) =>
      node.recommended
      || node.needsPractice
      || node.status === 'ready'
      || node.code === activeCode.value,
  )

  if (seeds.length === 0 && graph.value.nodes[0]) {
    seeds.push(graph.value.nodes[0])
  }

  for (const seed of seeds) {
    codes.add(seed.code)
    for (const prereq of seed.prerequisites) {
      codes.add(prereq.code)
    }
    for (const unlockCode of seed.unlocksCodes) {
      codes.add(unlockCode)
    }
  }

  return codes
})

const focusedBands = computed<Set<string>>(() => {
  const levels = new Set<string>()
  for (const code of focusNeighborhoodCodes.value) {
    const node = nodeByCode.value.get(code)
    if (node) {
      levels.add(node.level)
    }
  }
  return levels
})

/**
 * Connected codes when a specific node is hovered/focused/selected.
 */
const highlightedCodes = computed<Set<string> | null>(() => {
  if (!activeCode.value) {
    return null
  }
  const node = nodeByCode.value.get(activeCode.value)
  if (!node) {
    return null
  }
  const related = new Set<string>([node.code])
  for (const prereq of node.prerequisites) {
    related.add(prereq.code)
  }
  for (const targetCode of node.unlocksCodes) {
    related.add(targetCode)
  }
  return related
})

function isEdgeHighlighted(edge: GraphEdge): boolean {
  if (!activeCode.value) {
    return false
  }
  return edge.from === activeCode.value || edge.to === activeCode.value
}

function isEdgeDimmed(edge: GraphEdge): boolean {
  if (activeCode.value) {
    return !isEdgeHighlighted(edge)
  }
  if (focusMode.value) {
    return (
      !focusNeighborhoodCodes.value.has(edge.from)
      && !focusNeighborhoodCodes.value.has(edge.to)
    )
  }
  return false
}

function isNodeDimmed(node: GraphNode): boolean {
  if (highlightedCodes.value) {
    return !highlightedCodes.value.has(node.code)
  }
  if (focusMode.value) {
    return !focusNeighborhoodCodes.value.has(node.code)
  }
  return false
}

function isBandDimmed(band: GraphBand): boolean {
  if (!focusMode.value) {
    return false
  }
  return !focusedBands.value.has(band.level)
}

function splitTitleLines(title: string, maxChars = 17): string[] {
  const trimmed = title.trim()
  if (trimmed.length <= maxChars) {
    return [trimmed]
  }

  const words = trimmed.split(/\s+/)
  if (words.length === 1) {
    return [trimmed]
  }

  let firstLine = ''
  let splitIndex = 0
  for (let i = 0; i < words.length; i++) {
    const candidate = firstLine ? `${firstLine} ${words[i]}` : words[i]!
    if (candidate.length <= maxChars || i === 0) {
      firstLine = candidate
      splitIndex = i + 1
    } else {
      break
    }
  }

  const rest = words.slice(splitIndex).join(' ')
  if (!rest) {
    return [firstLine]
  }

  if (rest.length > maxChars + 4) {
    return [firstLine, `${rest.slice(0, maxChars + 2).trimEnd()}…`]
  }

  return [firstLine, rest]
}

function nodeAriaLabel(node: GraphNode): string {
  const parts = [`#${node.orderIndex}`, node.level, node.title]
  if (node.recommended) {
    parts.push(roadmap.current)
  } else if (node.status === 'ready') {
    parts.push(roadmap.available)
  } else if (node.status === 'completed') {
    parts.push(roadmap.statusCompleted)
  } else {
    parts.push(roadmap.statusLocked)
  }

  if (node.needsPractice) {
    parts.push(roadmap.needsPractice)
  }

  if (node.status === 'locked' && node.prerequisites.length > 0) {
    parts.push(
      `${roadmap.lockedNeeds}${node.prerequisites.map((p) => p.title).join(', ')}`,
    )
  }

  return parts.join(' · ')
}

function activateNode(node: GraphNode): void {
  activeCode.value = node.code
  emit('select', node)

  if (node.status === 'locked') {
    return
  }

  if (!props.previewOnly) {
    void navigateTo(`/lesson/${node.id}`)
  }
}

function onNodeKeydown(event: KeyboardEvent, node: GraphNode): void {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    activateNode(node)
  }
}
</script>

<template>
  <div class="space-y-4" data-testid="roadmap-graph">
    <!-- Top bar: hint, legend, and focus-mode toggle -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-[0.8125rem] text-[var(--ink-muted)]">
        {{ roadmap.graphHint }}
      </p>

      <div class="flex flex-wrap items-center gap-3">
        <!-- Compact state legend -->
        <div class="flex flex-wrap items-center gap-x-3.5 gap-y-1 text-[0.75rem] text-[var(--ink-muted)]">
          <span class="inline-flex items-center gap-1.5">
            <span class="size-2.5 rounded-full bg-[var(--accent)] shadow-[0_0_0_3px_var(--accent-dim)]" />
            {{ roadmap.current }}
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="size-2.5 rounded-full bg-[var(--color-amber-bright)]" />
            {{ roadmap.needsPractice }}
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="size-2.5 rounded-full border border-[var(--accent)] bg-[color-mix(in_oklab,var(--accent)_22%,transparent)]" />
            {{ roadmap.statusCompleted }}
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="size-2.5 rounded-full border border-[var(--line-strong)] bg-[var(--surface)]" />
            {{ roadmap.statusLocked }}
          </span>
        </div>

        <button
          type="button"
          class="rounded-full border border-[var(--line-strong)] bg-[var(--surface)] px-3 py-1 text-[0.75rem] font-medium text-[var(--ink-muted)] transition-colors hover:border-[var(--accent)] hover:text-[var(--ink)]"
          :aria-pressed="focusMode"
          data-testid="roadmap-focus-toggle"
          @click="focusMode = !focusMode"
        >
          {{ focusMode ? roadmap.mapAll : roadmap.mapFocus }}
        </button>
      </div>
    </div>

    <!-- Active / inspected node callout (shows prerequisite requirements for locked nodes or quick context) -->
    <div
      v-if="defaultInspectedNode"
      class="rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-4 py-3 transition-colors"
      data-testid="roadmap-graph-callout"
    >
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 space-y-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="tnum label">
              {{ defaultInspectedNode.level }} · #{{ defaultInspectedNode.orderIndex }} · {{ defaultInspectedNode.category }}
            </span>

            <span
              v-if="defaultInspectedNode.recommended"
              class="rounded-full bg-[var(--accent-dim)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.06em] text-[var(--accent)]"
            >
              {{ roadmap.current }}
            </span>
            <span
              v-else-if="defaultInspectedNode.status === 'ready'"
              class="rounded-full bg-[var(--accent-dim)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.06em] text-[var(--accent)]"
            >
              {{ roadmap.available }}
            </span>

            <span
              v-if="defaultInspectedNode.needsPractice"
              class="rounded-full bg-[color-mix(in_oklab,var(--color-amber-bright)_18%,transparent)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.06em] text-[var(--color-amber-bright)]"
            >
              {{ roadmap.needsPractice }}
            </span>
          </div>

          <p class="text-[0.9375rem] font-medium text-[var(--ink)]">
            {{ defaultInspectedNode.title }}
          </p>

          <p
            v-if="defaultInspectedNode.needsPractice"
            class="text-[0.8125rem] text-[var(--color-amber-bright)]"
          >
            {{ roadmap.needsPracticeHint }}
          </p>

          <p
            v-if="defaultInspectedNode.status === 'locked'"
            class="text-[0.8125rem] text-[var(--ink-muted)]"
          >
            <template v-if="defaultInspectedNode.prerequisites.length > 0">
              <span class="text-[var(--ink-faint)]">{{ roadmap.lockedNeeds }}</span>
              <span>{{ defaultInspectedNode.prerequisites.map((p) => p.title).join(' / ') }}</span>
            </template>
            <template v-else>
              {{ roadmap.lockedHint }}
            </template>
          </p>
          <p
            v-else
            class="line-clamp-2 text-[0.8125rem] text-[var(--ink-muted)]"
          >
            {{ defaultInspectedNode.practicePrompt }}
          </p>
        </div>

        <UiButtonLink
          v-if="defaultInspectedNode.status !== 'locked' && !previewOnly"
          :to="`/lesson/${defaultInspectedNode.id}`"
          :variant="defaultInspectedNode.recommended ? 'primary' : 'quiet'"
          size="md"
        >
          {{ defaultInspectedNode.status === 'completed' ? roadmap.revisitCard : roadmap.startCard }}
        </UiButtonLink>
      </div>
    </div>

    <!-- Responsive SVG Graph Canvas -->
    <div class="overflow-x-auto rounded-3xl border border-[var(--line)] bg-[var(--surface-sunken)] p-2 sm:p-4">
      <svg
        :viewBox="`0 0 ${graph.width} ${graph.height}`"
        class="mx-auto block h-auto w-full min-w-[640px] select-none"
        role="group"
        :aria-label="roadmap.title"
      >
        <!-- CEFR Horizontal Bands -->
        <g class="roadmap-bands">
          <g
            v-for="band in graph.bands"
            :key="band.level"
            :data-band-level="band.level"
            class="transition-opacity duration-200"
            :class="isBandDimmed(band) ? 'opacity-35' : 'opacity-100'"
          >
            <rect
              :x="12"
              :y="band.y"
              :width="graph.width - 24"
              :height="band.height"
              rx="18"
              fill="var(--surface)"
              stroke="var(--line)"
              stroke-width="1"
            />
            <!-- CEFR Band Pill Header -->
            <g :transform="`translate(28, ${band.y + 26})`">
              <rect
                x="0"
                y="-14"
                width="36"
                height="22"
                rx="6"
                fill="var(--surface-sunken)"
                stroke="var(--line-strong)"
                stroke-width="1"
              />
              <text
                x="18"
                y="1"
                text-anchor="middle"
                class="tnum fill-[var(--ink-muted)] text-[11px] font-semibold tracking-[0.08em]"
              >
                {{ band.level }}
              </text>
            </g>
          </g>
        </g>

        <!-- Prerequisite Bezier Edges -->
        <g class="roadmap-edges" fill="none">
          <path
            v-for="edge in graph.edges"
            :key="edge.id"
            :d="edge.d"
            :data-edge-from="edge.from"
            :data-edge-to="edge.to"
            stroke-linecap="round"
            class="transition-opacity duration-200"
            :stroke="
              isEdgeHighlighted(edge)
                ? 'var(--accent)'
                : edge.completed
                  ? 'color-mix(in oklab, var(--accent) 52%, transparent)'
                  : edge.unlocked
                    ? 'color-mix(in oklab, var(--accent) 36%, var(--line-strong))'
                    : 'var(--line-strong)'
            "
            :stroke-width="isEdgeHighlighted(edge) ? 2.6 : edge.completed ? 1.8 : 1.35"
            :stroke-dasharray="edge.unlocked ? undefined : '4 4'"
            :opacity="isEdgeDimmed(edge) ? 0.16 : 1"
          />
        </g>

        <!-- Grammar Point Nodes -->
        <g class="roadmap-nodes">
          <g
            v-for="node in graph.nodes"
            :key="node.code"
            tabindex="0"
            role="link"
            :data-node-code="node.code"
            :data-node-status="node.status"
            :data-node-state="node.state"
            :data-needs-practice="node.needsPractice ? 'true' : undefined"
            :aria-disabled="node.status === 'locked' ? 'true' : undefined"
            :aria-label="nodeAriaLabel(node)"
            :transform="`translate(${node.x}, ${node.y})`"
            class=" outline-none transition-opacity duration-200"
            :class="[
              node.status === 'locked' ? 'cursor-default' : 'cursor-pointer',
              isNodeDimmed(node) ? 'opacity-25' : 'opacity-100',
            ]"
            @mouseenter="activeCode = node.code"
            @focus="activeCode = node.code"
            @click="activateNode(node)"
            @keydown="onNodeKeydown($event, node)"
          >
            <!-- Outer halo for the primary recommended card or active focus -->
            <circle
              v-if="node.recommended || activeCode === node.code"
              :r="graph.nodeRadius + 7"
              fill="none"
              :stroke="
                node.needsPractice
                  ? 'color-mix(in oklab, var(--color-amber-bright) 45%, transparent)'
                  : 'var(--accent-dim)'
              "
              :stroke-width="node.recommended ? 5 : 3"
            />

            <!-- Needs-practice warm amber ring when not the primary recommended card -->
            <circle
              v-else-if="node.needsPractice && node.status !== 'locked'"
              :r="graph.nodeRadius + 5"
              fill="none"
              stroke="color-mix(in oklab, var(--color-amber-bright) 42%, transparent)"
              stroke-width="3.5"
            />

            <!-- Main node circle -->
            <circle
              :r="graph.nodeRadius"
              :fill="
                node.recommended
                  ? 'var(--accent)'
                  : node.status === 'ready'
                    ? 'color-mix(in oklab, var(--accent) 20%, var(--surface-strong))'
                    : node.status === 'completed'
                      ? 'color-mix(in oklab, var(--accent) 14%, var(--surface-strong))'
                      : 'var(--surface-strong)'
              "
              :stroke="
                node.needsPractice && node.status !== 'locked'
                  ? 'var(--color-amber-bright)'
                  : node.recommended || node.status === 'ready'
                    ? 'var(--accent)'
                    : node.status === 'completed'
                      ? 'color-mix(in oklab, var(--accent) 50%, transparent)'
                      : 'var(--line-strong)'
              "
              :stroke-width="node.recommended || node.status === 'ready' || node.needsPractice ? 2.2 : 1.5"
            />

            <!-- Completed check icon or orderIndex numeral -->
            <path
              v-if="node.status === 'completed'"
              d="M -6 0.5 L -1.8 4.8 L 6.5 -4.2"
              fill="none"
              :stroke="node.recommended ? 'var(--accent-ink)' : 'var(--accent)'"
              stroke-width="2.4"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
            <text
              v-else
              y="4"
              text-anchor="middle"
              class="tnum text-[11px] font-semibold"
              :fill="
                node.recommended
                  ? 'var(--accent-ink)'
                  : node.status === 'ready'
                    ? 'var(--ink)'
                    : 'var(--ink-faint)'
              "
            >
              {{ node.orderIndex }}
            </text>

            <!-- NeedsPractice badge dot in top-right corner of the node -->
            <g v-if="node.needsPractice" transform="translate(16, -16)">
              <circle
                r="6.5"
                fill="var(--color-amber-bright)"
                stroke="var(--surface-strong)"
                stroke-width="1.5"
              />
            </g>

            <!-- Node label (1-2 lines below circle) -->
            <text
              text-anchor="middle"
              class="text-[11px] font-medium"
              :fill="node.status === 'locked' ? 'var(--ink-faint)' : 'var(--ink)'"
            >
              <tspan
                v-for="(line, lineIdx) in splitTitleLines(node.title)"
                :key="lineIdx"
                x="0"
                :y="graph.nodeRadius + 16 + lineIdx * 13"
              >
                {{ line }}
              </tspan>
            </text>
          </g>
        </g>
      </svg>
    </div>
  </div>
</template>
