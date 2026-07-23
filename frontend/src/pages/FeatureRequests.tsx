import { useEffect, useState, type CSSProperties } from "react";
import { ChevronUp } from "lucide-react";
import { viewsMaxApi, type FeatureRequest } from "@/lib/api-service";
import { toast } from "sonner";
import { CARD, Btn, Chip, SectionHead } from "@/components/analytics/primitives";
import { Field, TextInput } from "@/components/analytics/Modal";

const CATEGORIES = ["Feature", "Improvement", "Integration", "Other"];

const textareaStyle: CSSProperties = {
  width: "100%", boxSizing: "border-box", background: "var(--paper-1)", border: "1px solid var(--line-1)",
  borderRadius: 12, padding: "11px 14px", fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-1)",
  outline: "none", resize: "vertical", lineHeight: 1.5,
};

const FeatureRequests = () => {
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [category, setCategory] = useState("Feature");
  const [submitting, setSubmitting] = useState(false);

  const [requests, setRequests] = useState<FeatureRequest[]>([]);
  const [loading, setLoading] = useState(true);

  const loadRequests = async () => {
    const result = await viewsMaxApi.getFeatureRequests("top");
    if (result.success && result.data) {
      setRequests(result.data);
    }
    setLoading(false);
  };

  useEffect(() => {
    loadRequests();
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim() || !description.trim()) {
      toast.error("Please add a title and description.");
      return;
    }

    setSubmitting(true);
    const result = await viewsMaxApi.createFeatureRequest({
      title: title.trim(),
      description: description.trim(),
      category,
    });
    setSubmitting(false);

    if (result.success && result.data) {
      toast.success("Thanks! Your feature request was submitted.");
      setTitle("");
      setDescription("");
      setCategory("Feature");
      setRequests((prev) => [result.data!, ...prev]);
    } else {
      toast.error(result.error || "Couldn't submit your request. Please try again.");
    }
  };

  const handleUpvote = async (id: number) => {
    const result = await viewsMaxApi.upvoteFeatureRequest(id);
    if (result.success && result.data) {
      setRequests((prev) =>
        prev.map((r) =>
          r.id === id
            ? { ...r, upvotes_count: result.data!.upvotes_count, has_upvoted: result.data!.has_upvoted }
            : r
        )
      );
    } else {
      toast.error(result.error || "Couldn't register your vote.");
    }
  };

  return (
    <div style={{ margin: "-24px", padding: 24, background: "var(--paper-1)", minHeight: "calc(100vh - 4rem)" }}>
      <div style={{ display: "flex", flexDirection: "column", gap: 18, maxWidth: 820, margin: "0 auto" }}>
        <SectionHead eyebrow="YOU SHAPE THE ROADMAP" title="Request a feature." />

        {/* Submit form */}
        <form onSubmit={handleSubmit} style={{ ...CARD, padding: 24 }}>
          <p style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-2)", margin: "0 0 18px", lineHeight: 1.5 }}>
            Tell us what would make ViewsMax more useful for you. Upvote ideas you'd like to see below.
          </p>
          <Field label="Title" hint={`${title.length}/120`}>
            <TextInput value={title} onChange={(e) => setTitle(e.target.value)} placeholder="A short summary of your idea" maxLength={120} />
          </Field>
          <Field label="Category">
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
              {CATEGORIES.map((c) => (
                <Chip key={c} tone="tag" active={category === c} onClick={() => setCategory(c)}>{c}</Chip>
              ))}
            </div>
          </Field>
          <Field label="Description">
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="What problem would this solve? How would it work?"
              rows={4}
              style={textareaStyle}
              onFocus={(e) => { e.currentTarget.style.borderColor = "var(--vm-red)"; e.currentTarget.style.boxShadow = "0 0 0 3px var(--vm-red-tint-l)"; }}
              onBlur={(e) => { e.currentTarget.style.borderColor = "var(--line-1)"; e.currentTarget.style.boxShadow = "none"; }}
            />
          </Field>
          <div style={{ display: "flex", justifyContent: "flex-end" }}>
            <Btn type="submit" icon="arrow-up-right">{submitting ? "Submitting…" : "Submit request"}</Btn>
          </div>
        </form>

        {/* List */}
        <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
          <div className="vm-eyebrow" style={{ color: "var(--ink-on-paper-3)" }}>WHAT OTHERS ARE REQUESTING</div>
          {loading ? (
            <div style={{ display: "flex", justifyContent: "center", padding: 32 }}>
              <div style={{ width: 22, height: 22, borderRadius: "50%", border: "3px solid var(--line-2)", borderTopColor: "var(--vm-red)", animation: "spin 0.8s linear infinite" }} />
            </div>
          ) : requests.length === 0 ? (
            <div style={{ ...CARD, padding: 32, textAlign: "center", fontFamily: "var(--font-body)", fontSize: 13.5, color: "var(--ink-on-paper-3)" }}>
              No requests yet — be the first to suggest something!
            </div>
          ) : (
            requests.map((req) => {
              const voted = req.has_upvoted;
              return (
                <div key={req.id} style={{ ...CARD, display: "flex", alignItems: "flex-start", gap: 16, padding: 16 }}>
                  <button
                    type="button"
                    onClick={() => handleUpvote(req.id)}
                    style={{
                      display: "flex", flexDirection: "column", alignItems: "center", gap: 2, padding: "8px 12px", cursor: "pointer",
                      borderRadius: 12, minWidth: 52,
                      background: voted ? "var(--vm-volt-tint-l)" : "var(--paper-1)",
                      border: "1px solid " + (voted ? "var(--vm-volt-deep)" : "var(--line-1)"),
                      color: voted ? "var(--vm-volt-deep)" : "var(--ink-on-paper-2)",
                    }}
                  >
                    <ChevronUp size={16} strokeWidth={2.4} />
                    <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13, fontVariantNumeric: "tabular-nums" }}>{req.upvotes_count}</span>
                  </button>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: "flex", flexWrap: "wrap", alignItems: "center", gap: 8, marginBottom: 4 }}>
                      <span style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 15, color: "var(--ink-on-paper-1)" }}>{req.title}</span>
                      {req.category && <Chip tone="ghost">{req.category}</Chip>}
                      {req.status && <Chip tone="aqua">{req.status}</Chip>}
                    </div>
                    <p style={{ fontFamily: "var(--font-body)", fontSize: 13.5, color: "var(--ink-on-paper-2)", margin: 0, lineHeight: 1.5 }}>{req.description}</p>
                  </div>
                </div>
              );
            })
          )}
        </div>
      </div>
    </div>
  );
};

export default FeatureRequests;
