import { afterEach, describe, expect, it, vi } from "vitest";
import { viewsMaxApi } from "@/lib/api-service";

// Bug regression: signup used to surface only the generic "Validation failed"
// message, hiding the per-field reasons the backend returns in `errors`.
describe("viewsMaxApi.register error mapping", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function stubFetch(body: unknown, ok = false, status = 422) {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => ({
        ok,
        status,
        json: async () => body,
      })),
    );
  }

  it("surfaces per-field validation messages, not just 'Validation failed'", async () => {
    stubFetch({
      success: false,
      message: "Validation failed",
      errors: {
        email: ["The email has already been taken."],
        password: ["The password field confirmation does not match."],
      },
    });

    const result = await viewsMaxApi.register("Jo", "taken@example.com", "password123", "password123", false);

    expect(result.success).toBe(false);
    expect(result.error).toContain("The email has already been taken.");
    expect(result.error).toContain("The password field confirmation does not match.");
  });

  it("falls back to the generic message when no field errors are present", async () => {
    stubFetch({ success: false, message: "Validation failed" });

    const result = await viewsMaxApi.register("Jo", "x@example.com", "password123", "password123", false);

    expect(result.success).toBe(false);
    expect(result.error).toBe("Validation failed");
  });
});
