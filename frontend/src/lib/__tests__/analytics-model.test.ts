import { describe, it, expect } from "vitest";
import { goalLabel, goalLabelMap } from "@/lib/analytics-model";
import type { GoalType } from "@/lib/api-service";

const goalTypes: GoalType[] = [
  { value: "conversion", label: "Purchase" },
  { value: "email-signup", label: "Email signup" },
];

describe("goalLabel — DB-driven labels", () => {
  const labels = goalLabelMap(goalTypes);

  it("resolves a known type to its DB label", () => {
    expect(goalLabel("conversion", labels)).toBe("Purchase");
    expect(goalLabel("email-signup", labels)).toBe("Email signup");
  });

  it("falls back to a de-slugged, title-cased label for custom types", () => {
    expect(goalLabel("demo-requested", labels)).toBe("Demo Requested");
    expect(goalLabel("vip_call", labels)).toBe("Vip Call");
  });

  it("does not invent a label when the DB list is empty", () => {
    expect(goalLabel("conversion")).toBe("Conversion");
  });
});
