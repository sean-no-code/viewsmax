// Shared page for the free transcript tools. Layout from the Claude Design
// handoff (design-import/tiktok-transcript-with-ads.dc.html): bookmark strip
// under the nav, then ads | tool | ads on wide screens, styled with the
// design-system tokens in index.css.
import { useState } from "react";
import { Link } from "react-router-dom";
import {
  Youtube, Instagram, Music2, Copy, Download, Check, Captions, ChevronRight, Sparkles, Loader2, AlertCircle, type LucideIcon,
} from "lucide-react";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import BookmarkBar from "@/components/free-tools/BookmarkBar";
import { AdRail, TOOL_ADS } from "@/components/free-tools/ToolAds";
import { useSeo } from "@/hooks/useSeo";
import { PLATFORMS, PLATFORM_LIST, buildJsonLd, type PlatformKey } from "@/lib/transcript-tools";
import { viewsMaxApi } from "@/lib/api-service";
import { toast } from "sonner";

const ICONS: Record<PlatformKey, LucideIcon> = { youtube: Youtube, tiktok: Music2, instagram: Instagram };

interface Segment { text: string; startMs: number; endMs: number; }
interface TranscriptResult { text: string; segments: Segment[]; language: string | null; cached: boolean; }

const msToMmss = (ms: number) => {
  const t = Math.floor(ms / 1000);
  return `${Math.floor(t / 60)}:${String(t % 60).padStart(2, "0")}`;
};
const msToSrt = (ms: number) => {
  const t = Math.floor(ms / 1000);
  const hh = String(Math.floor(t / 3600)).padStart(2, "0");
  const mm = String(Math.floor((t % 3600) / 60)).padStart(2, "0");
  const ss = String(t % 60).padStart(2, "0");
  return `${hh}:${mm}:${ss},${String(ms % 1000).padStart(3, "0")}`;
};
const toSrt = (segments: Segment[]) =>
  segments.map((s, i) => `${i + 1}\n${msToSrt(s.startMs)} --> ${msToSrt(s.endMs)}\n${s.text}\n`).join("\n");

const downloadFile = (filename: string, content: string) => {
  const blob = new Blob([content], { type: "text/plain;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
};

const CARD = "rounded-[18px] border border-line-1 bg-paper-0";
const H2 = "text-center font-display text-[26px] font-extrabold leading-[1.06] tracking-[-0.025em] sm:text-[32px]";
const SMALL_BTN =
  "inline-flex h-9 items-center gap-2 rounded-[10px] border border-line-1 bg-paper-0 px-3.5 font-body text-sm font-semibold text-ink-on-paper-1 transition-colors duration-200 hover:border-ink-on-paper-1";

export default function TranscriptTool({ platform }: { platform: PlatformKey }) {
  const cfg = PLATFORMS[platform];
  const Icon = ICONS[platform];
  const [url, setUrl] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<TranscriptResult | null>(null);
  const [copied, setCopied] = useState(false);

  useSeo({
    title: cfg.seo.title,
    description: cfg.seo.description,
    keywords: cfg.seo.keywords,
    canonicalPath: cfg.slug,
    ogImage: "https://viewsmax.com/og-image.png",
    jsonLd: buildJsonLd(cfg),
  });

  const generate = async () => {
    if (!url.trim()) {
      setError(`Paste a ${cfg.name} link first.`);
      return;
    }
    setLoading(true);
    setError(null);
    setResult(null);
    const res = await viewsMaxApi.getTranscript(platform, url.trim());
    setLoading(false);
    if (res.success && res.data) setResult(res.data);
    else setError(res.error || "Couldn't fetch a transcript for that link.");
  };

  const lines =
    result?.segments?.length
      ? result.segments.map((s) => ({ time: msToMmss(s.startMs), text: s.text }))
      : result?.text
        ? [{ time: "", text: result.text }]
        : [];
  const plainText = result?.text || lines.map((l) => l.text).join(" ");

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(plainText);
      setCopied(true);
      toast.success("Transcript copied");
      setTimeout(() => setCopied(false), 1500);
    } catch {
      toast.error("Couldn't copy — try selecting the text.");
    }
  };

  const others = PLATFORM_LIST.filter((p) => p.key !== platform);

  return (
    <div data-prerender-ready className="flex min-h-screen flex-col bg-paper-1 font-body text-ink-on-paper-1">
      <LandingNav />
      <BookmarkBar />

      {/* ads | tool | ads */}
      <div className="mx-auto grid w-full max-w-[1500px] flex-1 grid-cols-1 items-start justify-center gap-6 px-4 pb-20 pt-10 sm:px-6 sm:pt-12 min-[1240px]:grid-cols-[minmax(220px,280px)_minmax(0,760px)_minmax(220px,280px)]">
        <AdRail ads={TOOL_ADS.slice(0, 2)} platform={cfg.name} side="left" />

        <main className="flex w-full min-w-0 max-w-[760px] flex-col items-center justify-self-center">
          {/* Hero + input */}
          <span className="inline-flex items-center gap-2 rounded-full border border-line-1 bg-paper-0 px-3.5 py-[7px] text-xs font-bold text-ink-on-paper-2">
            <Icon className="h-3.5 w-3.5 text-vm-red" aria-hidden /> {cfg.name} transcript · 100% free
          </span>
          <h1 className="mb-4 mt-[22px] text-center font-display text-[clamp(34px,5vw,56px)] font-extrabold leading-[1.02] tracking-[-0.03em] [text-wrap:balance]">
            {cfg.h1}
          </h1>
          <p className="mb-8 max-w-[660px] text-center text-[19px] leading-[1.5] text-ink-on-paper-2 [text-wrap:pretty]">{cfg.subhead}</p>

          <form
            className="flex w-full max-w-[740px] flex-wrap gap-3"
            onSubmit={(e) => { e.preventDefault(); if (!loading) generate(); }}
          >
            <input
              type="url"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder={cfg.urlPlaceholder}
              aria-label={`${cfg.name} video URL`}
              className="h-[50px] min-w-0 flex-[1_1_320px] rounded-xl border border-line-2 bg-paper-0 px-3.5 font-body text-[15px] text-ink-on-paper-1 outline-none transition-[border-color,box-shadow] duration-200 placeholder:text-ink-on-paper-3 focus:border-vm-red focus:shadow-[0_0_0_3px_rgba(255,31,61,.18)]"
            />
            <button
              type="submit"
              disabled={loading}
              className="inline-flex h-[50px] items-center justify-center rounded-xl bg-vm-red px-[26px] font-body text-[15px] font-bold text-white shadow-[4px_4px_0_0_var(--ink-on-paper-1)] transition-all duration-[120ms] hover:bg-vm-red-hot active:translate-y-px active:bg-vm-red-deep active:shadow-[2px_2px_0_0_var(--ink-on-paper-1)] disabled:cursor-wait disabled:opacity-80"
            >
              {loading ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Generating…</> : cfg.ctaLabel}
            </button>
          </form>
          <p className="mb-[30px] mt-3.5 text-xs font-semibold text-ink-on-paper-3">No login · No watermark · Free forever</p>

          {/* Result / loading / error */}
          {(loading || error || result) && (
            <div className={`${CARD} w-full max-w-[740px] overflow-hidden`} aria-live="polite">
              {loading ? (
                <div className="flex items-center justify-center gap-2 py-12 text-sm text-ink-on-paper-2">
                  <Loader2 className="h-5 w-5 animate-spin" /> Fetching the {cfg.name} transcript…
                </div>
              ) : error ? (
                <div className="flex items-start gap-3 px-5 py-6 text-sm">
                  <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-vm-red" />
                  <span>{error}</span>
                </div>
              ) : result ? (
                <>
                  <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line-1 px-[18px] py-3.5">
                    <span className="flex items-center gap-2 text-[15px] font-bold">
                      <Sparkles className="h-4 w-4 text-vm-red" aria-hidden /> Transcript
                    </span>
                    <div className="flex gap-2">
                      <button type="button" className={SMALL_BTN} onClick={copy}>
                        {copied ? <Check className="h-[15px] w-[15px]" /> : <Copy className="h-[15px] w-[15px]" />} Copy
                      </button>
                      <button type="button" className={SMALL_BTN} onClick={() => downloadFile(`${platform}-transcript.txt`, plainText)}>
                        <Download className="h-[15px] w-[15px]" /> TXT
                      </button>
                      {result.segments?.length ? (
                        <button type="button" className={SMALL_BTN} onClick={() => downloadFile(`${platform}-transcript.srt`, toSrt(result.segments))}>
                          <Captions className="h-[15px] w-[15px]" /> SRT
                        </button>
                      ) : null}
                    </div>
                  </div>
                  <div className="max-h-[380px] overflow-y-auto px-5 py-3.5">
                    {lines.map((l, i) => (
                      <div key={i} className="flex gap-3 py-[7px] text-[15px] leading-[1.4]">
                        {l.time && (
                          <span className="min-w-[34px] shrink-0 pt-0.5 font-mono text-[12.5px] tabular-nums text-ink-on-paper-3">{l.time}</span>
                        )}
                        <span>{l.text}</span>
                      </div>
                    ))}
                  </div>
                </>
              ) : null}
            </div>
          )}

          {/* How it works */}
          <section className="w-full pt-16">
            <h2 className={H2}>How to convert {cfg.name} to text</h2>
            <div className="mt-8 grid gap-4 sm:grid-cols-3">
              {cfg.steps.map((s, i) => (
                <div key={s.title} className={`${CARD} p-5`}>
                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-[color:var(--vm-red-tint-l)] font-display text-sm font-extrabold text-vm-red">{i + 1}</div>
                  <div className="mt-3 font-display text-[17px] font-bold leading-tight tracking-[-0.01em]">{s.title}</div>
                  <div className="mt-1.5 text-sm leading-[1.45] text-ink-on-paper-2">{s.desc}</div>
                </div>
              ))}
            </div>
          </section>

          {/* Features */}
          <section className="mt-16 w-full rounded-[26px] bg-paper-2 px-6 py-10 sm:px-10">
            <h2 className={H2}>Why use our {cfg.name} transcript generator</h2>
            <div className="mt-8 grid gap-6 sm:grid-cols-2">
              {cfg.features.map((f) => (
                <div key={f.title} className="flex gap-3">
                  <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[color:var(--vm-volt-tint-l)] text-vm-volt-deep">
                    <Check className="h-3.5 w-3.5" strokeWidth={3} aria-hidden />
                  </span>
                  <div>
                    <div className="font-bold">{f.title}</div>
                    <div className="text-sm leading-[1.45] text-ink-on-paper-2">{f.desc}</div>
                  </div>
                </div>
              ))}
            </div>
          </section>

          {/* Use cases */}
          <section className="w-full pt-16">
            <h2 className={H2}>Who uses {cfg.name} transcripts</h2>
            <div className="mt-8 grid gap-4 sm:grid-cols-2">
              {cfg.useCases.map((u) => (
                <div key={u.persona} className={`${CARD} p-5`}>
                  <div className="font-display text-[17px] font-bold leading-tight tracking-[-0.01em]">{u.persona}</div>
                  <div className="mt-1.5 text-sm leading-[1.45] text-ink-on-paper-2">{u.desc}</div>
                </div>
              ))}
            </div>
          </section>

          {/* FAQ */}
          <section className="w-full pt-16">
            <h2 className={H2}>{cfg.name} transcript FAQ</h2>
            <div className="mt-8 space-y-3">
              {cfg.faq.map((f) => (
                <details key={f.q} className={`group ${CARD} px-5 py-4`}>
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-3 font-bold [&::-webkit-details-marker]:hidden">
                    {f.q}
                    <ChevronRight className="h-4 w-4 shrink-0 text-ink-on-paper-3 transition-transform group-open:rotate-90" aria-hidden />
                  </summary>
                  <p className="mt-3 text-sm leading-[1.5] text-ink-on-paper-2">{f.a}</p>
                </details>
              ))}
            </div>
          </section>

          {/* Cross-links */}
          <section className="w-full pt-16 text-center">
            <h2 className={H2}>More free transcript tools</h2>
            <div className="mt-6 flex flex-wrap justify-center gap-3">
              {others.map((p) => {
                const OIcon = ICONS[p.key];
                return (
                  <Link
                    key={p.key}
                    to={p.slug}
                    className="inline-flex items-center gap-2 rounded-full border border-line-1 bg-paper-0 px-5 py-2.5 text-sm font-bold text-ink-on-paper-1 transition-[box-shadow,transform,border-color] duration-200 hover:-translate-x-0.5 hover:-translate-y-0.5 hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]"
                  >
                    <OIcon className="h-4 w-4 text-vm-red" aria-hidden /> {p.name} transcript
                  </Link>
                );
              })}
            </div>
          </section>
        </main>

        <AdRail ads={TOOL_ADS.slice(2, 4)} platform={cfg.name} side="right" />
      </div>
      <LandingFooter />
    </div>
  );
}
