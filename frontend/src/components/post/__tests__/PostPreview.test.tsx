import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { PostPreview } from "@/components/post/composer";
import type { GoogleBusinessOptions } from "@/lib/api-service";

const gbp = (o: Partial<GoogleBusinessOptions>): GoogleBusinessOptions => ({
  topic_type: "STANDARD", cta_type: "", cta_url: "",
  event_title: "", event_start: "", event_end: "",
  coupon_code: "", redeem_url: "", terms: "",
  ...o,
});

describe("PostPreview", () => {
  it("shows a Google Business update's text and button", () => {
    render(<PostPreview id="google_business" text="Fresh bread every morning." media={[]}
      googleBusiness={gbp({ cta_type: "ORDER", cta_url: "https://bakery.example/order" })} handle="Corner Bakery" />);

    expect(screen.getByText("Fresh bread every morning.")).toBeInTheDocument();
    expect(screen.getByText("Order online")).toBeInTheDocument();
    expect(screen.getByText("Corner Bakery")).toBeInTheDocument();
  });

  it("shows a Google Business event's title and times", () => {
    render(<PostPreview id="google_business" text="Join us." media={[]}
      googleBusiness={gbp({ topic_type: "EVENT", event_title: "Bread tasting night", event_start: "2026-11-06T18:00", event_end: "2026-11-06T21:00", cta_type: "SIGN_UP", cta_url: "https://x.example" })} />);

    expect(screen.getByText("Bread tasting night")).toBeInTheDocument();
    expect(screen.getByText("Fri 6 Nov 2026, 18:00 – 21:00")).toBeInTheDocument();
    expect(screen.getByText("Join us.")).toBeInTheDocument();
    expect(screen.getByText("Sign up")).toBeInTheDocument();
  });

  it("shows a Google Business offer's coupon and Google's own View offer button", () => {
    render(<PostPreview id="google_business" text="20% off." media={[]}
      googleBusiness={gbp({ topic_type: "OFFER", event_title: "20% off croissants", event_start: "2026-11-02T07:00", event_end: "2026-11-08T12:00", coupon_code: "CROISSANT20", cta_type: "ORDER" })} />);

    expect(screen.getByText("20% off croissants")).toBeInTheDocument();
    expect(screen.getByText("Mon 2 Nov 2026, 07:00 – Sun 8 Nov 2026, 12:00")).toBeInTheDocument();
    expect(screen.getByText("CROISSANT20")).toBeInTheDocument();
    expect(screen.getByText("View offer")).toBeInTheDocument();
    expect(screen.queryByText("Order online")).toBeNull();
  });

  it("shows Bluesky's text", () => {
    render(<PostPreview id="bluesky" text="Hello Bluesky" media={[]} />);

    expect(screen.getByText("Hello Bluesky")).toBeInTheDocument();
  });
});
