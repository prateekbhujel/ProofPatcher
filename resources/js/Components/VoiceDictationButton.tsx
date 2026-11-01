import React, { useState, useRef, useEffect } from 'react';
import { Mic, MicOff, AlertCircle } from 'lucide-react';

export type VoiceDictationState = 'idle' | 'listening' | 'transcribing' | 'unsupported' | 'error';

interface VoiceDictationButtonProps {
  onTranscript: (transcript: string) => void;
  disabled?: boolean;
  label?: string;
}

export function VoiceDictationButton({
  onTranscript,
  disabled = false,
  label = 'Voice',
}: VoiceDictationButtonProps) {
  const [state, setState] = useState<VoiceDictationState>('idle');
  const [statusMessage, setStatusMessage] = useState<string | null>(null);
  const recognitionRef = useRef<any>(null);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const mediaStreamRef = useRef<MediaStream | null>(null);
  const chunksRef = useRef<BlobPart[]>([]);
  const mountedRef = useRef(false);
  const transcriptionRef = useRef<AbortController | null>(null);

  function cleanupMedia() {
    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== 'inactive') {
      try {
        mediaRecorderRef.current.stop();
      } catch {
        // ignore
      }
    }
    if (mediaStreamRef.current) {
      for (const track of mediaStreamRef.current.getTracks()) {
        track.stop();
      }
      mediaStreamRef.current = null;
    }
  }

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      transcriptionRef.current?.abort();
      chunksRef.current = [];
      if (recognitionRef.current) {
        try {
          recognitionRef.current.abort();
        } catch {
          // ignore
        }
      }
      cleanupMedia();
    };
  }, []);

  async function startLocalMediaRecorder() {
    if (
      typeof navigator === 'undefined' ||
      !navigator.mediaDevices?.getUserMedia ||
      typeof MediaRecorder === 'undefined'
    ) {
      setState('error');
      setStatusMessage('Speech recognition service is currently unavailable. Click Voice to retry or type directly.');
      return;
    }

    try {
      setStatusMessage('Requesting microphone access for local transcription…');
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      if (!mountedRef.current) {
        stream.getTracks().forEach((track) => track.stop());
        return;
      }
      mediaStreamRef.current = stream;
      chunksRef.current = [];

      const recorder = new MediaRecorder(stream);
      mediaRecorderRef.current = recorder;

      recorder.ondataavailable = (ev) => {
        if (mountedRef.current && ev.data && ev.data.size > 0) {
          chunksRef.current.push(ev.data);
        }
      };

      recorder.onstop = async () => {
        for (const track of stream.getTracks()) {
          track.stop();
        }
        mediaStreamRef.current = null;

        if (!mountedRef.current) return;

        const blob = new Blob(chunksRef.current, { type: recorder.mimeType || 'audio/webm' });
        if (blob.size === 0) {
          setState('idle');
          setStatusMessage('No audio captured. Click Voice to try again.');
          return;
        }

        setState('transcribing');
        setStatusMessage('Transcribing locally with Whisper…');

        try {
          const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
          const xsrf = match ? decodeURIComponent(match[1]) : '';
          const form = new FormData();
          form.append('audio', blob, 'voice.webm');
          const controller = new AbortController();
          transcriptionRef.current = controller;

          const res = await fetch('/issues/voice-transcribe', {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'X-XSRF-TOKEN': xsrf,
            },
            body: form,
            signal: controller.signal,
          });
          const data = await res.json();
          if (!mountedRef.current || controller.signal.aborted) return;
          if (res.ok && data?.status === 'ok' && typeof data.transcript === 'string' && data.transcript.trim()) {
            const text = data.transcript.trim();
            onTranscript(text);
            setState('idle');
            setStatusMessage(`Added: "${text.slice(0, 48)}${text.length > 48 ? '…' : ''}"`);
          } else {
            setState('error');
            setStatusMessage('Speech recognition service is currently unavailable. Click Voice to retry or type directly.');
          }
        } catch {
          if (!mountedRef.current) return;
          setState('error');
          setStatusMessage('Speech recognition service is currently unavailable. Click Voice to retry or type directly.');
        } finally {
          transcriptionRef.current = null;
        }
      };

      recorder.start();
      setState('listening');
      setStatusMessage('Recording locally… speak now, then click Listening to transcribe.');
    } catch {
      if (!mountedRef.current) return;
      setState('error');
      setStatusMessage('Microphone permission was denied. Allow mic access in browser settings.');
    }
  }

  function handleToggle() {
    if (disabled || state === 'transcribing') return;

    if (state === 'listening') {
      if (mediaRecorderRef.current && mediaRecorderRef.current.state === 'recording') {
        mediaRecorderRef.current.stop();
        return;
      }
      if (recognitionRef.current) {
        try {
          recognitionRef.current.stop();
        } catch {
          // ignore
        }
      }
      setState('idle');
      setStatusMessage(null);
      return;
    }

    const SpeechRecognitionCtor =
      typeof window !== 'undefined'
        ? (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition
        : null;

    if (!SpeechRecognitionCtor) {
      void startLocalMediaRecorder();
      return;
    }

    try {
      const recognition = new SpeechRecognitionCtor();
      recognition.continuous = false;
      recognition.interimResults = false;
      recognition.lang = 'en-US';

      recognition.onstart = () => {
        if (!mountedRef.current) return;
        setState('listening');
        setStatusMessage('Listening… speak now (click Mic to stop).');
      };

      recognition.onresult = (event: any) => {
        if (!mountedRef.current) return;
        const transcript = Array.from(event.results || [])
          .map((res: any) => res[0]?.transcript ?? '')
          .join(' ')
          .trim();
        if (transcript) {
          onTranscript(transcript);
          setStatusMessage(`Added: "${transcript.slice(0, 48)}${transcript.length > 48 ? '…' : ''}"`);
        }
      };

      recognition.onerror = (event: any) => {
        if (!mountedRef.current) return;
        const code = event?.error || 'unknown';
        if (code === 'network' || code === 'service-not-allowed') {
          // Automatically fall back to local on-device Whisper recording when browser cloud speech is unavailable
          void startLocalMediaRecorder();
          return;
        }
        setState('error');
        if (code === 'not-allowed') {
          setStatusMessage('Microphone permission was denied. Allow mic access in browser settings.');
        } else if (code === 'no-speech') {
          setStatusMessage('No speech detected. Click Voice to try again.');
        } else {
          setStatusMessage(`Speech recognition error (${code}).`);
        }
      };

      recognition.onend = () => {
        if (!mountedRef.current) return;
        setState((prev) => (prev === 'listening' && (!mediaRecorderRef.current || mediaRecorderRef.current.state !== 'recording') ? 'idle' : prev));
      };

      recognitionRef.current = recognition;
      setState('listening');
      setStatusMessage('Requesting microphone access…');
      recognition.start();
    } catch {
      void startLocalMediaRecorder();
    }
  }

  const isListening = state === 'listening';
  const isTranscribing = state === 'transcribing';

  return (
    <div className="inline-flex flex-wrap items-center gap-2">
      <button
        type="button"
        onClick={handleToggle}
        disabled={disabled || isTranscribing}
        aria-pressed={isListening}
        aria-label={isListening ? 'Stop voice dictation' : 'Start voice dictation'}
        className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-medium border transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 disabled:opacity-40 ${
          isListening
            ? 'bg-rose-50 text-rose-800 border-rose-300 dark:bg-rose-950/70 dark:text-rose-200 dark:border-rose-800'
            : isTranscribing
              ? 'bg-accent-50 text-accent-800 border-accent-300 dark:bg-accent-950/70 dark:text-accent-200 dark:border-accent-800'
              : state === 'unsupported' || state === 'error'
                ? 'bg-amber-50 text-amber-900 border-amber-300 dark:bg-amber-950/70 dark:text-amber-200 dark:border-amber-800'
                : 'bg-slate-50 text-slate-700 border-slate-300 hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700 dark:hover:bg-slate-700'
        }`}
      >
        {isListening ? (
          <MicOff className="w-3.5 h-3.5 text-rose-600 dark:text-rose-400" />
        ) : (
          <Mic className="w-3.5 h-3.5 text-slate-600 dark:text-slate-300" />
        )}
        <span>{isListening ? 'Listening…' : isTranscribing ? 'Transcribing…' : label}</span>
      </button>

      {statusMessage && (
        <span
          role="status"
          aria-live="polite"
          className={`inline-flex items-center gap-1 text-[11px] ${
            state === 'error' || state === 'unsupported'
              ? 'text-amber-800 dark:text-amber-300 font-medium'
              : isListening
                ? 'text-rose-700 dark:text-rose-300 font-medium'
                : 'text-slate-600 dark:text-slate-400'
          }`}
        >
          {(state === 'error' || state === 'unsupported') && (
            <AlertCircle className="w-3 h-3 shrink-0" />
          )}
          <span>{statusMessage}</span>
        </span>
      )}
    </div>
  );
}
