import { describe, it, expect } from "vitest";
import { PLATFORM_CAPS, PMAP, tumblrTagList } from "@/components/post/composer";
import { hasBrandIcon } from "@/components/post/brand-icons";

describe("tumblrTagList", () => {
  it("splits on commas, drops a leading # and blanks, keeps the first spelling of a repeat", () => {
    expect(tumblrTagList("#art, process ,, Art,##behind the scenes")).toEqual(["art", "process", "behind the scenes"]);
  });

  it("gives no tags for an empty field", () => {
    expect(tumblrTagList("")).toEqual([]);
    expect(tumblrTagList(" , #, ")).toEqual([]);
  });
});

describe("Tumblr in the composer catalog", () => {
  it("takes text, images and video, with NPF's 4096-character text block limit", () => {
    expect(PLATFORM_CAPS.tumblr).toEqual({ text: true, image: true, video: true });
    expect(PMAP.tumblr.limit).toBe(4096);
    expect(hasBrandIcon("tumblr")).toBe(true);
  });
});
