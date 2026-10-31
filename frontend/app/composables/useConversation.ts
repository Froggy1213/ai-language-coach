import { Track, type LocalAudioTrack, type RemoteAudioTrack, type RemoteTrack } from 'livekit-client'
import { computed, onUnmounted, ref, shallowRef, watch, type Ref } from 'vue'
import { useVoiceSession } from '~/composables/useVoiceSession'

/**
 * What the conversation surface should be showing right now.
 *
 * Derived rather than assigned, because the honest answer depends on two audio
 * streams at once: the learner's microphone and the agent's voice.
 */
export type OrbState = 'idle' | 'listening' | 'speaking' | 'thinking' | 'error'

export interface UseConversationOptions {
  audioElement?: Ref<HTMLAudioElement | null | undefined>
}

/** Rolling window of sound levels used to shape the agent's speaking envelope. */
const ENVELOPE_FRAMES = 120
/** A frame counts as speech above this RMS (0–1). */
const SPEECH_THRESHOLD = 0.035
/** Recent frames to check for speech hangover (~300ms at 60ms polling). */
const RECENT_SPEECH_FRAMES = 5

/**
 * The conversation controller.
 *
 * `useVoiceSession` owns the connection; this layer owns everything the orb needs
 * to move — two audio analysers (learner and agent), a smoothed level envelope,
 * and the derived {@link OrbState}. Analysis is started inside the click handler
 * so the `AudioContext` is created while the user gesture is still valid, which
 * is what Safari requires.
 */
export function useConversation(options: UseConversationOptions = {}) {
  const voice = useVoiceSession(options)

  /**
   * Loudness fallback for the agent's state, used only when the room does not
   * publish `lk.agent.state` (the authoritative source is `voice.agentState`).
   * The envelope below cannot tell "waiting" from "thinking", which is exactly
   * why it no longer decides `thinking` on its own.
   */
  const speaking = shallowRef(false)
  /** Fundamental of the learner's microphone, refreshed from every analysed frame. */
  const micLevel = ref(0)

  let context: AudioContext | null = null
  let micSource: MediaStreamAudioSourceNode | null = null
  let micAnalyser: AnalyserNode | null = null
  let agentSource: MediaStreamAudioSourceNode | null = null
  let agentAnalyser: AnalyserNode | null = null
  let micData: Uint8Array<ArrayBuffer> | null = null
  let agentData: Uint8Array<ArrayBuffer> | null = null
  let envelope: number[] = []
  let poll: number | null = null
  let disposed = false

  /**
   * Creates the shared audio context and both analysers. Must be called
   * synchronously from a user gesture (see the note on the composable).
   */
  function arm(): void {
    if (disposed || !import.meta.client || context) {
      return
    }

    const AudioContextCtor = window.AudioContext
      ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext

    if (!AudioContextCtor) {
      return
    }

    try {
      context = new AudioContextCtor()
    } catch {
      context = null
      return
    }

    void context.resume().catch(() => {})

    // Frames per second the analyser reports; 48 covers the voice band while
    // keeping the orb's frequency response legible instead of hash.
    agentAnalyser = context.createAnalyser()
    agentAnalyser.fftSize = 2048
    agentAnalyser.smoothingTimeConstant = 0.65
    agentData = new Uint8Array(agentAnalyser.frequencyBinCount)

    micAnalyser = context.createAnalyser()
    micAnalyser.fftSize = 1024
    micAnalyser.smoothingTimeConstant = 0.75
    micData = new Uint8Array(micAnalyser.frequencyBinCount)
  }

  function connectMic(track: LocalAudioTrack): void {
    if (!context || !micAnalyser) {
      return
    }

    try {
      micSource?.disconnect()
    } catch {
      // Already detached.
    }

    try {
      micSource = context.createMediaStreamSource(new MediaStream([track.mediaStreamTrack]))
      micSource.connect(micAnalyser)
    } catch {
      micSource = null
    }
  }

  function connectAgent(track: RemoteTrack): void {
    if (!context || !agentAnalyser || track.kind !== Track.Kind.Audio) {
      return
    }

    try {
      agentSource?.disconnect()
    } catch {
      // Already detached.
    }

    try {
      agentSource = context.createMediaStreamSource(
        new MediaStream([(track as RemoteAudioTrack).mediaStreamTrack]),
      )
      agentSource.connect(agentAnalyser)
    } catch {
      agentSource = null
    }
  }

  function disconnectAnalysers(): void {
    for (const source of [micSource, agentSource]) {
      try {
        source?.disconnect()
      } catch {
        // Already detached.
      }
    }

    micSource = null
    agentSource = null
    envelope = []
  }

  /** Lowest band of the spectrum, weighted toward the fundamental of speech. */
  function fundamentalOf(data: Uint8Array<ArrayBuffer>): number {
    const bins = 48
    let sum = 0

    for (let i = 2; i < bins; i += 1) {
      sum += (data[i] ?? 0) ** 2
    }

    return Math.sqrt(sum / (bins - 2)) / 255
  }

  function pollAudio(): void {
    poll = window.setTimeout(pollAudio, 60)

    if (disposed || document.hidden) {
      return
    }

    if (micAnalyser && micData) {
      micAnalyser.getByteFrequencyData(micData)
      micLevel.value = fundamentalOf(micData)
    }

    let agentVolume = 0

    if (agentAnalyser && agentData) {
      agentAnalyser.getByteFrequencyData(agentData)
      agentVolume = fundamentalOf(agentData)
    }

    // Room-reported levels carry the agent when the stream is not analysable
    // (a browser without Web Audio, or a track we never received directly).
    const roomAgent = voice.agentAudioLevel.value

    envelope.push(Math.max(agentVolume, roomAgent))

    if (envelope.length > ENVELOPE_FRAMES) {
      envelope.shift()
    }

    speaking.value = envelope.slice(-RECENT_SPEECH_FRAMES).some((value) => value > SPEECH_THRESHOLD)
  }

  function startAnalysis(): void {
    if (!context || poll !== null) {
      return
    }

    void context.resume().catch(() => {})
    pollAudio()
  }

  function stopAnalysis(): void {
    if (poll !== null) {
      window.clearTimeout(poll)
      poll = null
    }

    disconnectAnalysers()
    speaking.value = false
    micLevel.value = 0
    envelope = []
  }

  /**
   * Opens the room. Call this directly from a click handler: it arms the
   * `AudioContext` synchronously before any awaited work.
   */
  async function start(lessonCardId: string): Promise<void> {
    arm()

    if (context) {
      await context.resume().catch(() => {})
    }

    await voice.start(lessonCardId)

    if (!disposed && voice.status.value === 'connected') {
      startAnalysis()
    }
  }

  /** Keeps the analysers pointed at whichever tracks are live right now. */
  function syncTracks(): void {
    const micTrack = voice.localAudioTrack.value
    const agentTrack = voice.agentAudioTrack.value

    if (micTrack) {
      connectMic(micTrack)
    }

    if (agentTrack) {
      connectAgent(agentTrack)
    }
  }

  watch(() => voice.localAudioTrack.value, syncTracks)
  watch(() => voice.agentAudioTrack.value, syncTracks)
  watch(() => voice.status.value, (status) => {
    if (status === 'connected') {
      syncTracks()
    }

    if (status === 'ended' || status === 'error') {
      stopAnalysis()
    }
  })

  // A tab in the background should not hold an audio device open.
  if (import.meta.client) {
    const handleVisibility = (): void => {
      if (!context) {
        return
      }

      if (document.hidden) {
        void context.suspend().catch(() => {})
      } else {
        void context.resume().catch(() => {})
      }
    }

    document.addEventListener('visibilitychange', handleVisibility)

    onUnmounted(() => {
      document.removeEventListener('visibilitychange', handleVisibility)
    })
  }

  onUnmounted(() => {
    disposed = true
    stopAnalysis()

    const closing = context
    context = null
    micAnalyser = null
    agentAnalyser = null
    micData = null
    agentData = null

    if (closing) {
      void closing.close().catch(() => {})
    }
  })

  /**
   * The single visual state the orb renders.
   *
   * The agent's own report is authoritative: `listening` while it waits for the
   * learner, `thinking` while it plans a reply, `speaking` while it talks. Only
   * when that attribute is unavailable does the loudness envelope decide, since
   * a fallback can at least tell agent speech from silence. That fallback never
   * fakes `thinking` — a stuck "the AI is thinking" flag was the bug this
   * replaces — so the orb can stay in `listening` while the learner speaks.
   */
  const orbState = computed<OrbState>(() => {
    if (voice.status.value === 'error') {
      return 'error'
    }

    switch (voice.agentState.value) {
      case 'speaking':
        return 'speaking'
      case 'thinking':
        return 'thinking'
      case 'listening':
        return 'listening'
    }

    if (voice.status.value === 'connected') {
      return speaking.value ? 'speaking' : 'listening'
    }

    return 'idle'
  })

  /**
   * 0–1 loudness of whoever is speaking.
   *
   * The orb must react to the *learner* while they talk and to the *agent* while
   * it answers, so the source follows the same state the orb renders rather than
   * being one fixed stream. While the agent reports `speaking`, its room level
   * drives the orb; while it reports `thinking`, there is no voice to follow and
   * the orb gets a flat glow; while it reports `listening` — or the attribute is
   * unavailable and the loudness fallback is in charge — the learner's
   * microphone moves it. The larger of the room and analyser readings wins so a
   * quiet input cannot flatten the response.
   */
  const energy = computed(() => {
    switch (orbState.value) {
      case 'speaking':
        return Math.max(0.08, voice.agentAudioLevel.value)
      case 'thinking':
        return 0.1
      case 'listening':
        return Math.max(voice.localAudioLevel.value, micLevel.value)
      default:
        return 0
    }
  })

  return {
    ...voice,
    orbState,
    energy,
    start,
  }
}
