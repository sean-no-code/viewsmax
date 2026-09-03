import { useState } from "react";
import { Link } from "react-router-dom";
import {
  Youtube, Instagram, Music2, Copy, Download, Check, Captions, ChevronRight, Sparkles, Loader2, AlertCircle, type LucideIcon,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent } from "@/components/ui/card";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
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
    <div data-prerender-ready className="min-h-screen bg-background flex flex-col">
      <LandingNav />
      <main className="flex-1">
        {/* Hero + input */}
        <section className="mx-auto max-w-3xl px-4 pt-14 pb-8 text-center">
          <div className="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold text-muted-foreground">
            <Icon className="h-4 w-4" /> {cfg.name} transcript · 100% free
          </div>
          <h1 className="mt-5 text-4xl font-bold tracking-tight text-foreground sm:text-5xl">{cfg.h1}</h1>
          <p className="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">{cfg.subhead}</p>
          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Input
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder={cfg.urlPlaceholder}
              className="h-12 text-base"
              onKeyDown={(e) => e.key === "Enter" && !loading && generate()}
              aria-label={`${cfg.name} video URL`}
            />
            <Button onClick={generate} disabled={loading} className="h-12 px-6 text-base">
              {loading ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Generating…</> : cfg.ctaLabel}
            </Button>
          </div>
          <p className="mt-3 text-xs text-muted-foreground">No login · No watermark · Free forever</p>
        </section>

        {/* Result / loading / error */}
        {(loading || error || result) && (
          <section className="mx-auto max-w-3xl px-4 pb-6">
            <Card>
              <CardContent className="p-0">
                {loading ? (
                  <div className="flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground">
                    <Loader2 className="h-5 w-5 animate-spin" /> Fetching the {cfg.name} transcript…
                  </div>
                ) : error ? (
                  <div className="flex items-start gap-3 px-5 py-6 text-sm">
                    <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-destructive" />
                    <span className="text-foreground">{error}</span>
                  </div>
                ) : result ? (
                  <>
                    <div className="flex items-center justify-between gap-3 border-b px-5 py-3">
                      <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                        <Sparkles className="h-4 w-4 text-primary" /> Transcript
                      </div>
                      <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={copy}>
                          {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                          <span className="ml-1.5">Copy</span>
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => downloadFile(`${platform}-transcript.txt`, plainText)}>
                          <Download className="h-4 w-4" /> <span className="ml-1.5">TXT</span>
                        </Button>
                        {result.segments?.length ? (
                          <Button variant="outline" size="sm" onClick={() => downloadFile(`${platform}-transcript.srt`, toSrt(result.segments))}>
                            <Captions className="h-4 w-4" /> <span className="ml-1.5">SRT</span>
                          </Button>
                        ) : null}
                      </div>
                    </div>
                    <div className="max-h-[420px] overflow-y-auto px-5 py-4">
                      {lines.map((l, i) => (
                        <div key={i} className="flex gap-3 py-1.5 text-sm">
                          {l.time && <span className="shrink-0 pt-0.5 font-mono text-xs text-muted-foreground">{l.time}</span>}
                          <span className="text-foreground">{l.text}</span>
                        </div>
                      ))}
                    </div>
                  </>
                ) : null}
              </CardContent>
            </Card>
          </section>
        )}

        {/* How it works */}
        <section className="mx-auto max-w-4xl px-4 py-14">
          <h2 className="text-center text-2xl font-bold text-foreground">How to convert {cfg.name} to text</h2>
          <div className="mt-8 grid gap-6 sm:grid-cols-3">
            {cfg.steps.map((s, i) => (
              <div key={s.title} className="rounded-xl border bg-card p-5">
                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">{i + 1}</div>
                <div className="mt-3 font-semibold text-foreground">{s.title}</div>
                <div className="mt-1 text-sm text-muted-foreground">{s.desc}</div>
              </div>
            ))}
          </div>
        </section>

        {/* Features */}
        <section className="bg-muted/30 py-14">
          <div className="mx-auto max-w-4xl px-4">
            <h2 className="text-center text-2xl font-bold text-foreground">Why use our {cfg.name} transcript generator</h2>
            <div className="mt-8 grid gap-6 sm:grid-cols-2">
              {cfg.features.map((f) => (
                <div key={f.title} className="flex gap-3">
                  <Check className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                  <div>
                    <div className="font-semibold text-foreground">{f.title}</div>
                    <div className="text-sm text-muted-foreground">{f.desc}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Use cases */}
        <section className="mx-auto max-w-4xl px-4 py-14">
          <h2 className="text-center text-2xl font-bold text-foreground">Who uses {cfg.name} transcripts</h2>
          <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {cfg.useCases.map((u) => (
              <div key={u.persona} className="rounded-xl border bg-card p-5">
                <div className="font-semibold text-foreground">{u.persona}</div>
                <div className="mt-1 text-sm text-muted-foreground">{u.desc}</div>
              </div>
            ))}
          </div>
        </section>

        {/* FAQ */}
        <section className="bg-muted/30 py-14">
          <div className="mx-auto max-w-3xl px-4">
            <h2 className="text-center text-2xl font-bold text-foreground">{cfg.name} transcript FAQ</h2>
            <div className="mt-8 space-y-3">
              {cfg.faq.map((f) => (
                <details key={f.q} className="group rounded-xl border bg-card px-5 py-4">
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-3 font-semibold text-foreground">
                    {f.q}
                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90" />
                  </summary>
                  <p className="mt-3 text-sm text-muted-foreground">{f.a}</p>
                </details>
              ))}
            </div>
          </div>
        </section>

        {/* Cross-links */}
        <section className="mx-auto max-w-4xl px-4 py-14 text-center">
          <h2 className="text-2xl font-bold text-foreground">More free transcript tools</h2>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            {others.map((p) => {
              const OIcon = ICONS[p.key];
              return (
                <Link key={p.key} to={p.slug} className="inline-flex items-center gap-2 rounded-full border bg-card px-5 py-2.5 text-sm font-semibold text-foreground hover:bg-muted">
                  <OIcon className="h-4 w-4" /> {p.name} transcript
                </Link>
              );
            })}
          </div>
        </section>
      </main>
      <LandingFooter />
    </div>
  );
}
