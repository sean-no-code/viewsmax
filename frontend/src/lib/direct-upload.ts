// Direct-to-R2 (presigned) upload helpers for post media.
//
// The backend signs the URLs (see POST /api/posts/media/direct); these helpers
// move the bytes browser → bucket with real progress. XHR (not fetch) because
// fetch has no upload-progress events.

export interface DirectUploadPartUrl {
  part_number: number;
  url: string;
}

export interface DirectUploadSession {
  strategy: "relay" | "put" | "multipart";
  path?: string;
  url?: string;
  headers?: Record<string, string>;
  upload_id?: string;
  part_size?: number;
  parts?: DirectUploadPartUrl[];
  public_url?: string;
}

export interface UploadedPart {
  part_number: number;
  etag: string;
}

export interface PartSlice {
  partNumber: number;
  start: number;
  end: number;
}

// R2 rejects multipart uploads whose parts (except the last) are not all the
// SAME size, so slicing must happen at exact part_size boundaries.
export function splitIntoParts(fileSize: number, partSize: number): PartSlice[] {
  if (fileSize <= 0 || partSize <= 0) return [];

  const slices: PartSlice[] = [];
  for (let start = 0, n = 1; start < fileSize; start += partSize, n++) {
    slices.push({ partNumber: n, start, end: Math.min(start + partSize, fileSize) });
  }
  return slices;
}

// PUT a blob to a presigned URL. Resolves with the response ETag (null when
// the bucket's CORS config doesn't expose it — fatal for multipart).
export function putWithProgress(
  url: string,
  body: Blob,
  headers: Record<string, string> = {},
  onLoaded?: (bytes: number) => void,
): Promise<string | null> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("PUT", url);
    for (const [name, value] of Object.entries(headers)) xhr.setRequestHeader(name, value);

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && onLoaded) onLoaded(e.loaded);
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        if (onLoaded) onLoaded(body.size);
        resolve(xhr.getResponseHeader("ETag"));
      } else {
        reject(new Error(`Direct upload failed with status ${xhr.status}`));
      }
    };
    xhr.onerror = () => reject(new Error("Network error during direct upload"));
    xhr.send(body);
  });
}

// Upload all multipart parts through a small concurrent pool, retrying each
// part once. Reports aggregate bytes uploaded across all parts.
export async function uploadMultipartParts(
  file: Blob,
  session: { part_size: number; parts: DirectUploadPartUrl[] },
  onLoaded?: (totalBytes: number) => void,
  concurrency = 4,
): Promise<UploadedPart[]> {
  const slices = splitIntoParts(file.size, session.part_size);
  if (slices.length !== session.parts.length) {
    throw new Error("Part plan mismatch between client and server");
  }

  const loaded = new Array<number>(slices.length).fill(0);
  const report = () => onLoaded?.(loaded.reduce((sum, bytes) => sum + bytes, 0));
  const uploaded: UploadedPart[] = new Array(slices.length);

  let next = 0;
  const worker = async () => {
    while (next < slices.length) {
      const i = next++;
      const blob = file.slice(slices[i].start, slices[i].end);
      const attempt = () =>
        putWithProgress(session.parts[i].url, blob, {}, (bytes) => {
          loaded[i] = bytes;
          report();
        });

      let etag: string | null;
      try {
        etag = await attempt();
      } catch {
        loaded[i] = 0;
        report();
        etag = await attempt();
      }
      if (!etag) {
        throw new Error("Storage did not return an ETag (bucket CORS must expose it)");
      }
      uploaded[i] = { part_number: session.parts[i].part_number, etag };
    }
  };

  await Promise.all(
    Array.from({ length: Math.min(concurrency, slices.length) }, () => worker()),
  );

  return uploaded;
}
