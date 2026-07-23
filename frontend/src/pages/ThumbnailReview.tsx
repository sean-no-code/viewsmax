import { useState, useEffect, useCallback, useRef, useLayoutEffect } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Plus, X, Save, FolderOpen } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi, Thumbnail } from "@/lib/api-service";
import {
  DndContext,
  useSensor,
  useSensors,
  PointerSensor,
  DragEndEvent,
  DragStartEvent,
  DragOverlay,
  useDraggable,
} from "@dnd-kit/core";
import { CSS } from "@dnd-kit/utilities";

interface ThumbnailItem {
  id: string;
  thumbnail: Thumbnail;
  title: string;
  x: number;
  y: number;
}

const THUMBNAIL_WIDTH = 160; // YouTube thumbnail size (scaled down)
const THUMBNAIL_HEIGHT = 90; // 16:9 aspect ratio

const ThumbnailCard = ({ 
  item, 
  onRemove, 
  onTitleChange,
  zoom,
}: {
  item: ThumbnailItem;
  onRemove: (id: string) => void;
  onTitleChange: (id: string, title: string) => void;
  zoom: number;
}) => {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    isDragging,
  } = useDraggable({
    id: item.id,
  });
  const titleRef = useRef<HTMLDivElement>(null);

  const width = THUMBNAIL_WIDTH * zoom;
  const style = transform
    ? {
        position: 'absolute' as const,
        left: `${item.x * zoom + transform.x}px`,
        top: `${item.y * zoom + transform.y}px`,
        width: `${width}px`,
        transform: isDragging ? 'rotate(2deg)' : 'none',
        opacity: isDragging ? 0.8 : 1,
        zIndex: isDragging ? 1000 : 1,
        cursor: 'grab',
      }
    : {
        position: 'absolute' as const,
        left: `${item.x * zoom}px`,
        top: `${item.y * zoom}px`,
        width: `${width}px`,
        cursor: 'grab',
      };

  // Update contentEditable content when item.title changes
  useLayoutEffect(() => {
    if (titleRef.current) {
      const currentText = titleRef.current.textContent || "";
      if (currentText !== item.title && !titleRef.current.matches(':focus')) {
        titleRef.current.textContent = item.title || "";
      }
    }
  }, [item.title]);

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="transition-transform"
      {...listeners}
      {...attributes}
    >
      <Card className="shadow-lg hover:shadow-xl transition-shadow cursor-grab active:cursor-grabbing">
        <CardContent className="p-2">
          <div className="space-y-2">
            <div 
              className="relative bg-muted rounded overflow-hidden"
              style={{ 
                width: '100%', 
                aspectRatio: '16/9',
              }}
            >
              <img
                src={item.thumbnail.file_location || item.thumbnail.image_url}
                alt={item.title || "Thumbnail"}
                className="w-full h-full object-cover pointer-events-none"
                draggable={false}
              />
              <Button
                variant="ghost"
                size="icon"
                onClick={(e) => {
                  e.stopPropagation();
                  e.preventDefault();
                  onRemove(item.id);
                }}
                onMouseDown={(e) => {
                  e.stopPropagation();
                  e.preventDefault();
                }}
                onPointerDown={(e) => {
                  e.stopPropagation();
                  e.preventDefault();
                }}
                className="absolute top-1 right-1 h-6 w-6 bg-background/80 hover:bg-destructive hover:text-destructive-foreground z-10"
              >
                <X className="w-3 h-3" />
              </Button>
            </div>
            <div
              ref={titleRef}
              contentEditable
              suppressContentEditableWarning
              onBlur={(e) => {
                const newTitle = e.currentTarget.textContent?.trim() || "";
                onTitleChange(item.id, newTitle);
              }}
              onFocus={(e) => {
                // Clear placeholder text when focused if it's the default
                if (e.currentTarget.textContent === "Click and type to change title") {
                  e.currentTarget.textContent = "";
                }
              }}
              onMouseDown={(e) => {
                e.stopPropagation();
              }}
              onPointerDown={(e) => {
                e.stopPropagation();
              }}
              onClick={(e) => {
                e.stopPropagation();
              }}
              className="text-[10px] text-muted-foreground leading-tight break-words min-h-[2.5rem] px-2 py-1 rounded border border-transparent hover:border-border focus:border-primary focus:outline-none cursor-text"
              style={{
                display: '-webkit-box',
                WebkitLineClamp: 2,
                WebkitBoxOrient: 'vertical',
                overflow: 'hidden',
                wordBreak: 'break-word',
              }}
            >
              {item.title || "Click and type to change title"}
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

const ThumbnailReview = () => {
  const [thumbnails, setThumbnails] = useState<Thumbnail[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [thumbnailItems, setThumbnailItems] = useState<ThumbnailItem[]>([]);
  const [nextId, setNextId] = useState(1);
  const [zoom, setZoom] = useState(1);
  const [activeId, setActiveId] = useState<string | null>(null);
  const boardRef = useRef<HTMLDivElement>(null);

  const sensors = useSensors(
    useSensor(PointerSensor)
  );

  const loadThumbnails = useCallback(async () => {
    try {
      setIsLoading(true);
      const result = await viewsMaxApi.getThumbnails();
      
      if (result.success && result.data) {
        // Only show completed thumbnails
        const completedThumbnails = result.data.filter(
          (thumb: Thumbnail) => thumb.status === 'completed'
        );
        setThumbnails(completedThumbnails);
      }
    } catch (error) {
      console.error("Error loading thumbnails:", error);
      toast.error("Failed to load thumbnails");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    loadThumbnails();
  }, [loadThumbnails]);

  const handleAddThumbnail = (thumbnail: Thumbnail) => {
    if (!boardRef.current) return;
    
    const boardRect = boardRef.current.getBoundingClientRect();
    // Place new thumbnail in a random position on the board
    const x = Math.random() * (boardRect.width - THUMBNAIL_WIDTH * zoom) / zoom;
    const y = Math.random() * (boardRect.height - THUMBNAIL_HEIGHT * zoom) / zoom;

    const newItem: ThumbnailItem = {
      id: `thumb-${nextId}`,
      thumbnail,
      title: "", // Start with empty title to show default text
      x: Math.max(0, x),
      y: Math.max(0, y),
    };
    setThumbnailItems((prev) => [...prev, newItem]);
    setNextId((prev) => prev + 1);
    toast.success("Thumbnail added to review board");
  };

  const handleRemoveThumbnail = (id: string) => {
    setThumbnailItems((prev) => prev.filter((item) => item.id !== id));
    toast.success("Thumbnail removed from review board");
  };

  const handleTitleChange = (id: string, title: string) => {
    setThumbnailItems((prev) =>
      prev.map((item) => (item.id === id ? { ...item, title } : item))
    );
  };

  const handleDragStart = (event: DragStartEvent) => {
    setActiveId(event.active.id as string);
  };

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, delta } = event;
    
    if (delta && boardRef.current) {
      setThumbnailItems((items) =>
        items.map((item) => {
          if (item.id === active.id) {
            const newX = Math.max(0, item.x + delta.x / zoom);
            const newY = Math.max(0, item.y + delta.y / zoom);
            
            // Keep within board bounds
            const boardRect = boardRef.current!.getBoundingClientRect();
            const maxX = (boardRect.width / zoom) - THUMBNAIL_WIDTH;
            const maxY = (boardRect.height / zoom) - THUMBNAIL_HEIGHT;
            
            return {
              ...item,
              x: Math.min(maxX, newX),
              y: Math.min(maxY, newY),
            };
          }
          return item;
        })
      );
    }
    
    setActiveId(null);
  };

  // Handle mouse wheel zoom
  useEffect(() => {
    const handleWheel = (e: WheelEvent) => {
      if (!boardRef.current) return;
      
      // Only zoom when hovering over the board
      if (boardRef.current.contains(e.target as Node)) {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -0.1 : 0.1;
        setZoom((prevZoom) => Math.max(0.5, Math.min(2, prevZoom + delta)));
      }
    };

    const board = boardRef.current;
    if (board) {
      board.addEventListener('wheel', handleWheel, { passive: false });
      return () => {
        board.removeEventListener('wheel', handleWheel);
      };
    }
  }, []);

  const activeItem = activeId ? thumbnailItems.find((item) => item.id === activeId) : null;

  const availableThumbnails = thumbnails.filter(
    (thumb) => !thumbnailItems.some((item) => item.thumbnail.id === thumb.id)
  );

  const handleSetAllTitles = (title: string) => {
    setThumbnailItems((prev) =>
      prev.map((item) => ({ ...item, title }))
    );
  };

  const handleSaveBoard = async () => {
    if (thumbnailItems.length === 0) {
      toast.error("No thumbnails on the board to save");
      return;
    }

    try {
      const boardData = {
        items: thumbnailItems.map(item => ({
          thumbnailId: item.thumbnail.id,
          title: item.title,
          x: item.x,
          y: item.y,
        })),
        zoom,
      };
      
      const result = await viewsMaxApi.saveThumbnailBoard(boardData);
      
      if (result.success) {
        // Also save to localStorage as backup
        localStorage.setItem('thumbnail-board-saved', JSON.stringify({
          ...boardData,
          savedAt: new Date().toISOString(),
        }));
        toast.success("Board saved successfully");
      } else {
        throw new Error(result.error || 'Failed to save board');
      }
    } catch (error) {
      console.error("Error saving board:", error);
      toast.error(error instanceof Error ? error.message : "Failed to save board");
    }
  };

  const handleLoadBoard = () => {
    try {
      const savedData = localStorage.getItem('thumbnail-board-saved');
      if (!savedData) {
        toast.error("No saved board found");
        return;
      }

      const boardData = JSON.parse(savedData);
      
      // Match saved thumbnail IDs to current thumbnails
      const loadedItems: ThumbnailItem[] = [];
      let currentNextId = nextId;

      boardData.items.forEach((savedItem: { thumbnailId: string; title: string; x: number; y: number }) => {
        const thumbnail = thumbnails.find(t => t.id === savedItem.thumbnailId);
        if (thumbnail) {
          loadedItems.push({
            id: `thumb-${currentNextId}`,
            thumbnail,
            title: savedItem.title,
            x: savedItem.x,
            y: savedItem.y,
          });
          currentNextId++;
        }
      });

      if (loadedItems.length === 0) {
        toast.error("No matching thumbnails found in saved board");
        return;
      }

      setThumbnailItems(loadedItems);
      setNextId(currentNextId);
      
      if (boardData.zoom) {
        setZoom(boardData.zoom);
      }

      toast.success(`Loaded ${loadedItems.length} thumbnail(s) from saved board`);
    } catch (error) {
      console.error("Error loading board:", error);
      toast.error("Failed to load board");
    }
  };

  return (
    <div className="container mx-auto px-4 py-0 max-w-[95vw]">
      <div className="grid lg:grid-cols-5 gap-6">
        {/* Available Thumbnails Sidebar */}
        <div className="lg:col-span-1">
          <Card>
            <CardContent className="p-4">
              <div className="space-y-4 mb-4">
                <div className="space-y-2">
                  <Label className="text-sm font-semibold">Set All Titles</Label>
                  <Input
                    placeholder="Enter title for all thumbnails..."
                    onBlur={(e) => {
                      const title = e.target.value.trim();
                      if (title) {
                        handleSetAllTitles(title);
                      }
                    }}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        const title = (e.target as HTMLInputElement).value.trim();
                        if (title) {
                          handleSetAllTitles(title);
                          (e.target as HTMLInputElement).blur();
                        }
                      }
                    }}
                    className="text-sm"
                  />
                </div>
                <div className="flex gap-2">
                  {thumbnailItems.length > 0 && (
                    <Button
                      variant="default"
                      size="sm"
                      onClick={handleSaveBoard}
                      className="flex-1 gap-2"
                    >
                      <Save className="w-4 h-4" />
                      Save Board
                    </Button>
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleLoadBoard}
                    className="flex-1 gap-2"
                  >
                    <FolderOpen className="w-4 h-4" />
                    Load Board
                  </Button>
                </div>
              </div>
              <h2 className="text-lg font-semibold mb-4">Available Thumbnails</h2>
              {isLoading ? (
                <div className="text-center py-8 text-muted-foreground">
                  <p className="text-sm">Loading thumbnails...</p>
                </div>
              ) : availableThumbnails.length === 0 ? (
                <div className="text-center py-8 text-muted-foreground">
                  <p className="text-sm">No available thumbnails</p>
                  {thumbnails.length === 0 && (
                    <p className="text-xs mt-2">Create thumbnails first</p>
                  )}
                </div>
              ) : (
                <div className="space-y-3 max-h-[calc(100vh-300px)] overflow-y-auto">
                  {availableThumbnails.map((thumbnail) => (
                    <div
                      key={thumbnail.id}
                      className="relative aspect-video w-full bg-muted rounded-lg overflow-hidden group cursor-pointer hover:ring-2 hover:ring-primary transition-all"
                      onClick={() => handleAddThumbnail(thumbnail)}
                    >
                      <img
                        src={thumbnail.file_location || thumbnail.image_url}
                        alt={thumbnail.description || "Thumbnail"}
                        className="w-full h-full object-cover"
                      />
                      <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
                        <Button
                          size="sm"
                          variant="secondary"
                          className="opacity-0 group-hover:opacity-100 transition-opacity"
                          onClick={(e) => {
                            e.stopPropagation();
                            handleAddThumbnail(thumbnail);
                          }}
                        >
                          <Plus className="w-4 h-4 mr-2" />
                          Add
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Review Board */}
        <div className="lg:col-span-4">
          <Card>
            <CardContent className="p-4">
              <div
                ref={boardRef}
                className="relative w-full bg-muted/30 rounded-lg border-2 border-dashed border-muted-foreground/20"
                style={{
                  minHeight: '600px',
                  height: 'calc(100vh - 300px)',
                  overflow: 'hidden',
                }}
              >
                {thumbnailItems.length === 0 ? (
                  <div className="absolute inset-0 flex items-center justify-center text-center text-muted-foreground">
                    <div>
                      <p className="text-lg mb-2">No thumbnails on the board yet</p>
                      <p className="text-sm">Add thumbnails from the sidebar to get started</p>
                    </div>
                  </div>
                ) : (
                  <DndContext
                    sensors={sensors}
                    onDragStart={handleDragStart}
                    onDragEnd={handleDragEnd}
                  >
                    {thumbnailItems.map((item) => (
                      <ThumbnailCard
                        key={item.id}
                        item={item}
                        onRemove={handleRemoveThumbnail}
                        onTitleChange={handleTitleChange}
                        zoom={zoom}
                      />
                    ))}
                    <DragOverlay>
                      {activeItem && (
                        <div
                          style={{
                            width: `${THUMBNAIL_WIDTH * zoom}px`,
                            transform: 'rotate(2deg)',
                          }}
                        >
                          <Card className="shadow-2xl">
                            <CardContent className="p-2">
                              <div className="space-y-2">
                                <div 
                                  className="relative bg-muted rounded overflow-hidden"
                                  style={{ 
                                    width: '100%', 
                                    aspectRatio: '16/9',
                                  }}
                                >
                                  <img
                                    src={activeItem.thumbnail.file_location || activeItem.thumbnail.image_url}
                                    alt={activeItem.title || "Thumbnail"}
                                    className="w-full h-full object-cover"
                                    draggable={false}
                                  />
                                </div>
                                <div className="text-[10px] text-muted-foreground line-clamp-2 min-h-[2.5rem] leading-tight px-2 py-1">
                                  {activeItem.title || "Click and type to change title"}
                                </div>
                              </div>
                            </CardContent>
                          </Card>
                        </div>
                      )}
                    </DragOverlay>
                  </DndContext>
                )}
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
};

export default ThumbnailReview;
