import { describe, it, expect, vi, beforeEach } from "vitest";
import { viewsMaxApi } from "../api-service";

const fetchMock = vi.fn();
global.fetch = fetchMock as unknown as typeof fetch;

const jsonResponse = (ok: boolean, status: number, body: unknown) =>
  ({ ok, status, json: () => Promise.resolve(body) }) as unknown as Response;

describe("viewsMaxApi.getTranscript", () => {
  beforeEach(() => {
    fetchMock.mockReset();
    localStorage.setItem("viewsmax_auth_token", "secret-token");
  });

  it("posts the platform and url to the public transcript endpoint without auth headers", async () => {
    const data = { platform: "youtube", url: "https://youtu.be/abc", text: "hello world", segments: [], language: "en", cached: false };
    fetchMock.mockResolvedValueOnce(jsonResponse(true, 200, { data }));

    const res = await viewsMaxApi.getTranscript("youtube", "https://youtu.be/abc");

    expect(res).toEqual({ success: true, data });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toMatch(/\/api\/free-tools\/transcript$/);
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({ platform: "youtube", url: "https://youtu.be/abc" });
    expect(Object.keys(init.headers as Record<string, string>)).not.toContain("Authorization");
  });

  it("relays the server's message on a failed request", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(false, 422, { message: "That video has no captions." }));

    const res = await viewsMaxApi.getTranscript("tiktok", "https://www.tiktok.com/@a/video/1");

    expect(res.success).toBe(false);
    expect(res.error).toBe("That video has no captions.");
  });

  it("falls back to a status message when the error body is not JSON", async () => {
    fetchMock.mockResolvedValueOnce({ ok: false, status: 502, json: () => Promise.reject(new Error("bad json")) } as unknown as Response);

    const res = await viewsMaxApi.getTranscript("instagram", "https://www.instagram.com/reel/x/");

    expect(res).toEqual({ success: false, error: "Failed to fetch transcript (502)" });
  });

  it("returns the network error instead of throwing", async () => {
    fetchMock.mockRejectedValueOnce(new Error("Failed to fetch"));

    const res = await viewsMaxApi.getTranscript("youtube", "https://youtu.be/abc");

    expect(res).toEqual({ success: false, error: "Failed to fetch" });
  });
});
