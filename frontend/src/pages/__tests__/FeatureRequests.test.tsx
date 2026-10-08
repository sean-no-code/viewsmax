import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import FeatureRequests from "@/pages/FeatureRequests";
import type { FeatureRequest } from "@/lib/api-service";

const auth = vi.hoisted(() => ({ user: null as null | { id: number; is_admin?: boolean } }));
vi.mock("@/hooks/useAuth", () => ({ useAuth: () => ({ user: auth.user }) }));
const api = vi.hoisted(() => ({ getFeatureRequests: vi.fn(), updateFeatureRequestStatus: vi.fn(), upvoteFeatureRequest: vi.fn(), createFeatureRequest: vi.fn() }));
vi.mock("@/lib/api-service", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/lib/api-service")>();
  return { ...original, viewsMaxApi: { ...original.viewsMaxApi, ...api } };
});
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const fr = (over: Partial<FeatureRequest>): FeatureRequest => ({
  id: 1, title: "Idea", description: "Desc", category: "Feature", upvotes_count: 1, has_upvoted: false, status: "approved", is_mine: false, created_at: "2026-10-01T00:00:00Z", ...over,
});

describe("FeatureRequests", () => {
  beforeEach(() => {
    api.getFeatureRequests.mockResolvedValue({ success: true, data: [
      fr({ id: 1, title: "Mine, pending", status: "in_review", is_mine: true }),
      fr({ id: 2, title: "Public one", status: "approved" }),
      fr({ id: 3, title: "Shipped one", status: "implemented" }),
    ] });
  });

  it("labels statuses and tells the author a pending request is private", async () => {
    auth.user = { id: 7 };
    render(<FeatureRequests />);
    expect(await screen.findByText("Mine, pending")).toBeInTheDocument();
    expect(screen.getByText("In review")).toBeInTheDocument();
    expect(screen.getByText("Approved")).toBeInTheDocument();
    expect(screen.getByText("Implemented")).toBeInTheDocument();
    expect(screen.getByText("Only you can see this until it's approved.")).toBeInTheDocument();
    expect(screen.queryByRole("combobox")).toBeNull();
  });

  it("lets an admin change the status", async () => {
    auth.user = { id: 1, is_admin: true };
    api.getFeatureRequests.mockResolvedValueOnce({ success: true, data: [fr({ id: 1, title: "Mine, pending", status: "in_review", requested_by: "Ada" })] });
    api.updateFeatureRequestStatus.mockResolvedValue({ success: true, data: fr({ id: 1, status: "approved" }) });
    const user = userEvent.setup();
    render(<FeatureRequests />);
    const select = await screen.findByRole("combobox", { name: "Status of Mine, pending" });
    expect(screen.getByText("Requested by Ada")).toBeInTheDocument();
    expect(screen.queryByText("Only you can see this until it's approved.")).toBeNull();

    await user.selectOptions(select, "approved");
    expect(api.updateFeatureRequestStatus).toHaveBeenCalledWith(1, "approved");
    expect(select).toHaveValue("approved");
    expect(screen.getAllByText("Approved")).toHaveLength(2); // the status chip + the select option
  });
});
