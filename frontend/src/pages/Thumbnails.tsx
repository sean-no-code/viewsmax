import { useState, useEffect, useCallback, useRef } from "react";
import { useSearchParams } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { ImageIcon, Upload, Download, Wand2, Loader2, Trash2, Eye, X, User, Plus, Sparkles, Edit, ChevronLeft, ChevronRight, Copy, Link } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi, Thumbnail, Model, API_BASE_URL } from "@/lib/api-service";
import { useAIModelProcessing } from "@/contexts/AIModelProcessingContext";

const Thumbnails = () => {
  const [thumbnails, setThumbnails] = useState<Thumbnail[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isGenerating, setIsGenerating] = useState(false);
  const [newThumbnailDescription, setNewThumbnailDescription] = useState("");
  const [thumbnailQuantity, setThumbnailQuantity] = useState<string>("");
  const [selectedThumbnail, setSelectedThumbnail] = useState<Thumbnail | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  
  // Edit thumbnail state
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [editingThumbnail, setEditingThumbnail] = useState<Thumbnail | null>(null);
  const [editPrompt, setEditPrompt] = useState<string>("");
  const [isUpdatingThumbnail, setIsUpdatingThumbnail] = useState(false);
  
  // Model-related state
  const [models, setModels] = useState<Model[]>([]);
  const [selectedModelId, setSelectedModelId] = useState<string>("");
  const [isModelModalOpen, setIsModelModalOpen] = useState(false);
  const [isCreatingModel, setIsCreatingModel] = useState(false);
  const [newModelName, setNewModelName] = useState("");
  const [modelImages, setModelImages] = useState<File[]>([]);
  
  // New form fields for AI model creation
  const [age, setAge] = useState<number | undefined>(undefined);
  const [typeId, setTypeId] = useState<string>("");
  const [ethnicityId, setEthnicityId] = useState<string>("");
  const [baldShavedHead, setBaldShavedHead] = useState<boolean>(false);
  const [types, setTypes] = useState<Array<{ id: string | number; name: string }>>([]);
  const [ethnicities, setEthnicities] = useState<Array<{ id: string | number; name: string }>>([]);
  const [isLoadingTypes, setIsLoadingTypes] = useState(false);
  const [isLoadingEthnicities, setIsLoadingEthnicities] = useState(false);
  
  // Enhance description state
  const [isEnhancingDescription, setIsEnhancingDescription] = useState(false);

  // State for tracking current image index for each thumbnail (for parent navigation)
  const [thumbnailImageIndex, setThumbnailImageIndex] = useState<Record<string, number>>({});
  const [modalImageIndex, setModalImageIndex] = useState(0);

  // State for tracking processing time
  const [processingStartTimes, setProcessingStartTimes] = useState<Record<string, number>>({});
  const [elapsedSeconds, setElapsedSeconds] = useState<Record<string, number>>({});


  // Tab state
  const [activeTab, setActiveTab] = useState<string>("create");
  const [searchParams, setSearchParams] = useSearchParams();

  // Copy thumbnail state
  const [inspirationImage, setInspirationImage] = useState<File | null>(null);
  const [inspirationImageUrl, setInspirationImageUrl] = useState<string>("");
  const [inspirationInputMode, setInspirationInputMode] = useState<"upload" | "url">("upload");
  const [referenceImage, setReferenceImage] = useState<File | null>(null);
  const [userDefaultReferenceImage, setUserDefaultReferenceImage] = useState<{ has_default_image: boolean; image_url: string | null } | null>(null);
  const [isCopying, setIsCopying] = useState(false);
  const [selectedCopyThumbnail, setSelectedCopyThumbnail] = useState<Thumbnail | null>(null);
  const [isCopyModalOpen, setIsCopyModalOpen] = useState(false);
  const [copyPage, setCopyPage] = useState(1);
  const [generatePage, setGeneratePage] = useState(1);
  const COPY_PER_PAGE = 9;

  // Track active polls to prevent duplicate polling
  const activePollsRef = useRef<Set<string | number>>(new Set());

  // AI Model Processing Context
  const { addProcessingModel } = useAIModelProcessing();

  // Sync tab state with URL query params
  useEffect(() => {
    // Read query param on mount to set initial tab
    const viewParam = searchParams.get('view');
    if (viewParam === 'copy') {
      setActiveTab('copy');
    }
  }, [searchParams]);

  useEffect(() => {
    // Update URL when tab changes (without full page reload)
    if (activeTab === 'copy') {
      setSearchParams({ view: 'copy' });
    } else {
      setSearchParams({});
    }
  }, [activeTab, setSearchParams]);

  // Helper function to flatten all parents recursively
  const getAllParentThumbnails = (thumbnail: Thumbnail): Thumbnail[] => {
    const allParents: Thumbnail[] = [];
    const traverse = (thumb: Thumbnail) => {
      if (thumb.parents && Array.isArray(thumb.parents) && thumb.parents.length > 0) {
        thumb.parents.forEach(parent => {
          // Only include parents that have an image to display
          if (parent.file_location || parent.image_url) {
            allParents.push(parent);
            // Recursively get parents of parents if the parent has parents
            if (parent.parents && Array.isArray(parent.parents) && parent.parents.length > 0) {
              traverse(parent);
            }
          }
        });
      }
    };
    traverse(thumbnail);
    return allParents;
  };

  // Get all versions (current + all parents) for a thumbnail
  const getAllVersions = (thumbnail: Thumbnail): Thumbnail[] => {
    const parents = getAllParentThumbnails(thumbnail);
    return [thumbnail, ...parents];
  };

  const loadThumbnails = useCallback(async () => {
    try {
      setIsLoading(true);
      const result = await viewsMaxApi.getThumbnails({ per_page: 100 });
      
      if (result.success && result.data) {
        // Handle paginated response - data is in result.data.data
        const rawThumbnails = result.data.data || [];
        
        // Ensure all loaded thumbnails have a status (default to 'completed' if not set)
        const thumbnailsWithStatus = rawThumbnails.map(thumb => ({
          ...thumb,
          status: thumb.status || 'completed',
          type: thumb.type || 'generated' as const
        }));

        setThumbnails(thumbnailsWithStatus);
        
        // Check for any pending or processed thumbnails and start polling them
        const pendingGenerated = thumbnailsWithStatus.filter(thumb =>
          thumb.type === 'generated' && (thumb.status === 'pending' || thumb.status === 'processed')
        );
        pendingGenerated.forEach(thumb => {
          pollThumbnailStatus(thumb.id);
        });

        // Check for any pending or processing copied thumbnails and start polling them
        const pendingCopied = thumbnailsWithStatus.filter(thumb =>
          thumb.type === 'copied' && (thumb.status === 'pending' || thumb.status === 'processing')
        );
        pendingCopied.forEach(thumb => {
          pollCopyThumbnailStatus(thumb.id);
        });
      } else {
        throw new Error(result.error || 'Failed to load thumbnails');
      }
    } catch (error) {
      console.error('Error loading thumbnails:', error);
      toast.error("Failed to load thumbnails");
    } finally {
      setIsLoading(false);
    }
  }, []);

  const loadModels = useCallback(async () => {
    try {
      const result = await viewsMaxApi.getModels();
      
      if (result.success && result.data) {
        // Handle both direct array and nested data structure
        const modelsData = Array.isArray(result.data) 
          ? result.data 
          : Array.isArray((result.data as { data?: Model[] }).data) 
            ? (result.data as { data: Model[] }).data 
            : [];
        setModels(modelsData);
      } else {
        console.error('Failed to load models:', result.error);
        setModels([]); // Set empty array as fallback
      }
    } catch (error) {
      console.error('Error loading models:', error);
      setModels([]); // Set empty array as fallback
    }
  }, []);

  useEffect(() => {
    loadThumbnails();
    loadModels();
  }, [loadThumbnails, loadModels]);

  // Fetch user's default reference image when Copy tab becomes active
  useEffect(() => {
    if (activeTab === 'copy') {
      fetchUserDefaultReferenceImage();
    }
  }, [activeTab]);

  // Load types and ethnicities when modal opens
  useEffect(() => {
    if (isModelModalOpen) {
      loadTypes();
      loadEthnicities();
    }
  }, [isModelModalOpen]);

  // Track processing start times for thumbnails
  useEffect(() => {
    setProcessingStartTimes(prev => {
      const newStartTimes: Record<string, number> = { ...prev };
      thumbnails.forEach(thumb => {
        const allVersions = getAllVersions(thumb);
        allVersions.forEach(version => {
          const isProcessing = version.status === 'pending' || version.status === 'processed';
          if (isProcessing && !version.file_location && !version.image_url) {
            const key = `${thumb.id}-${version.id}`;
            if (!newStartTimes[key]) {
              // Use created_at if available, otherwise use current time
              const startTime = version.created_at 
                ? new Date(version.created_at).getTime() 
                : Date.now();
              newStartTimes[key] = startTime;
            }
          }
        });
      });
      return newStartTimes;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [thumbnails]);

  // Update elapsed time every second for processing thumbnails
  useEffect(() => {
    const interval = setInterval(() => {
      const newElapsed: Record<string, number> = {};
      Object.entries(processingStartTimes).forEach(([key, startTime]) => {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        newElapsed[key] = elapsed;
      });
      setElapsedSeconds(newElapsed);
    }, 1000);

    return () => clearInterval(interval);
  }, [processingStartTimes]);

  const loadTypes = async () => {
    try {
      setIsLoadingTypes(true);
      const result = await viewsMaxApi.getAIModelTypes();
      if (result.success && result.data) {
        // Handle both string arrays and object arrays
        const typesData = result.data.map(item => {
          if (typeof item === 'string') {
            // If it's a string, assume it's both id and name
            return { id: item, name: item };
          }
          const obj = item as { id: string | number; name?: string; value?: string };
          return {
            id: obj.id,
            name: obj.name || obj.value || String(obj.id)
          };
        });
        setTypes(typesData);
      } else {
        toast.error(result.error || "Failed to load types");
      }
    } catch (error) {
      console.error('Error loading types:', error);
      toast.error("Failed to load types");
    } finally {
      setIsLoadingTypes(false);
    }
  };

  const loadEthnicities = async () => {
    try {
      setIsLoadingEthnicities(true);
      const result = await viewsMaxApi.getAIModelEthnicities();
      if (result.success && result.data) {
        // Handle both string arrays and object arrays
        const ethnicitiesData = result.data.map(item => {
          if (typeof item === 'string') {
            // If it's a string, assume it's both id and name
            return { id: item, name: item };
          }
          const obj = item as { id: string | number; name?: string; value?: string };
          return {
            id: obj.id,
            name: obj.name || obj.value || String(obj.id)
          };
        });
        setEthnicities(ethnicitiesData);
      } else {
        toast.error(result.error || "Failed to load ethnicities");
      }
    } catch (error) {
      console.error('Error loading ethnicities:', error);
      toast.error("Failed to load ethnicities");
    } finally {
      setIsLoadingEthnicities(false);
    }
  };

  const generateThumbnail = async () => {
    if (!thumbnailQuantity) {
      toast.error("Please select how many thumbnails to generate");
      return;
    }

    setIsGenerating(true);
    try {
      const quantity = parseInt(thumbnailQuantity);
      
      // Send single API call with number_of_thumbnails parameter
      const result = await viewsMaxApi.generateThumbnail({
        description: newThumbnailDescription,
        number_of_thumbnails: quantity,
        ai_model_id: selectedModelId || undefined
      });
      
      if (result.success && result.data) {
        const thumbnails = result.data;
        const pendingThumbnails: Thumbnail[] = [];
        
        // Process all returned thumbnails
        thumbnails.forEach((thumbnail, index) => {
          const pendingThumbnail: Thumbnail = {
            ...thumbnail,
            status: 'pending'
          };
          pendingThumbnails.push(pendingThumbnail);

          // Start polling for status updates
          pollThumbnailStatus(thumbnail.id);
        });
        
        if (pendingThumbnails.length > 0) {
          setThumbnails(prev => [...pendingThumbnails, ...prev]);
          toast.success(`${pendingThumbnails.length} thumbnail${pendingThumbnails.length > 1 ? 's' : ''} generation started!`);
        } else {
          throw new Error('No thumbnails were created');
        }
      } else {
        throw new Error(result.error || 'Failed to generate thumbnails');
      }
    } catch (error) {
      console.error('Error generating thumbnails:', error);
      const errorMessage = error instanceof Error ? error.message : "Failed to generate thumbnails. Please try again.";
      toast.error(errorMessage);
    } finally {
      setIsGenerating(false);
    }
  };

  const pollThumbnailStatus = async (thumbnailId: string) => {
    const pollInterval = 20000; // Poll every 20 seconds
    const maxAttempts = 30; // Maximum 10 minutes of polling (30 * 20 seconds)
    let attempts = 0;

    const poll = async () => {
      try {
        const result = await viewsMaxApi.getThumbnailStatus(thumbnailId);

        if (result.success && result.data) {
          const updatedThumbnail = result.data;

          // Update the thumbnail in the list
          setThumbnails(prev =>
            prev.map(thumb =>
              thumb.id === thumbnailId
                ? { ...thumb, ...updatedThumbnail }
                : thumb
            )
          );

          // Check if processing is complete
          if (updatedThumbnail.status === 'completed') {
            toast.success("Thumbnail generated successfully!");
            return; // Stop polling
          } else if (updatedThumbnail.status === 'failed') {
            toast.error("Thumbnail generation failed!");
            return; // Stop polling
          }
        }

        // Continue polling if not complete and under max attempts
        attempts++;
        if (attempts < maxAttempts) {
          setTimeout(poll, pollInterval);
        } else {
          toast.error("Thumbnail generation timed out!");
        }
      } catch (error) {
        console.error('Error polling thumbnail status:', error);
        attempts++;
        if (attempts < maxAttempts) {
          setTimeout(poll, pollInterval);
        } else {
          toast.error("Failed to check thumbnail status!");
        }
      }
    };

    // Start polling after a short delay
    setTimeout(poll, pollInterval);
  };

  const deleteThumbnail = async (id: string) => {
    try {
      const result = await viewsMaxApi.deleteThumbnail(id);
      
      if (result.success) {
        setThumbnails(prev => prev.filter(thumb => thumb.id !== id));
        toast.success("Thumbnail deleted successfully!");
      } else {
        throw new Error(result.error || 'Failed to delete thumbnail');
      }
    } catch (error) {
      console.error('Error deleting thumbnail:', error);
      toast.error("Failed to delete thumbnail");
    }
  };

  const downloadThumbnail = async (id: string) => {
    try {
      const result = await viewsMaxApi.downloadThumbnail(id);
      
      if (result.success && result.data) {
        // Create a blob URL and trigger download
        const url = window.URL.createObjectURL(result.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `thumbnail-${id}.jpg`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
        toast.success("Thumbnail downloaded successfully!");
      } else {
        throw new Error(result.error || 'Failed to download thumbnail');
      }
    } catch (error) {
      console.error('Error downloading thumbnail:', error);
      toast.error("Failed to download thumbnail");
    }
  };

  const openThumbnailModal = async (thumbnail: Thumbnail) => {
    try {
      // Fetch the full thumbnail details including combined_prompts and parents
      const result = await viewsMaxApi.getThumbnailStatus(thumbnail.id);
      
      if (result.success && result.data) {
        setSelectedThumbnail(result.data);
        setModalImageIndex(0);
        setIsModalOpen(true);
      } else {
        // Fallback to the thumbnail data we already have
        setSelectedThumbnail(thumbnail);
        setModalImageIndex(0);
        setIsModalOpen(true);
      }
    } catch (error) {
      console.error('Error fetching thumbnail details:', error);
      // Fallback to the thumbnail data we already have
      setSelectedThumbnail(thumbnail);
      setModalImageIndex(0);
      setIsModalOpen(true);
    }
  };

  const openEditModal = (thumbnail: Thumbnail) => {
    setEditingThumbnail(thumbnail);
    setEditPrompt("");
    setIsEditModalOpen(true);
  };

  const duplicateThumbnail = (thumbnail: Thumbnail) => {
    if (thumbnail.description) {
      setNewThumbnailDescription(thumbnail.description);
      // Scroll to the description textarea
      setTimeout(() => {
        const descriptionElement = document.getElementById('thumbnail-description');
        if (descriptionElement) {
          descriptionElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
          descriptionElement.focus();
        }
      }, 100);
      toast.success("Description copied to input box");
    } else {
      toast.error("No description available to duplicate");
    }
  };

  // Copy Thumbnail action handlers
  const openCopyThumbnailModal = async (copyThumb: Thumbnail) => {
    try {
      // Fetch the full copy thumbnail details including base_image_path
      const result = await viewsMaxApi.getCopyThumbnailStatus(copyThumb.id);

      if (result.success && result.data) {
        // Merge API result with state data to ensure we have all fields
        const mergedData = {
          ...copyThumb,
          ...result.data,
          base_image_url: result.data.base_image_url || copyThumb.base_image_url,
          base_image_path: result.data.base_image_path || copyThumb.base_image_path,
          quality: result.data.quality || copyThumb.quality,
          method: result.data.method || copyThumb.method,
          created_at: result.data.created_at || copyThumb.created_at,
          updated_at: result.data.updated_at || copyThumb.updated_at,
        };

        setSelectedCopyThumbnail(mergedData);
        setIsCopyModalOpen(true);
      } else {
        setSelectedCopyThumbnail(copyThumb);
        setIsCopyModalOpen(true);
      }
    } catch (error) {
      console.error('Error fetching copy thumbnail details:', error);
      setSelectedCopyThumbnail(copyThumb);
      setIsCopyModalOpen(true);
    }
  };

  const downloadCopyThumbnail = async (copyThumb: Thumbnail | string | number) => {
    try {
      const id = typeof copyThumb === 'string' ? copyThumb :
                  typeof copyThumb === 'number' ? copyThumb.toString() :
                  copyThumb.id;

      const result = await viewsMaxApi.downloadCopyThumbnail(id);

      if (result.success && result.data) {
        const url = window.URL.createObjectURL(result.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `thumbnail-${id}.jpg`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
        toast.success("Copy thumbnail downloaded successfully!");
      } else {
        throw new Error(result.error || 'Failed to download copy thumbnail');
      }
    } catch (error) {
      console.error('Error downloading copy thumbnail:', error);
      toast.error("Failed to download copy thumbnail");
    }
  };

  const deleteCopyThumbnail = async (id: string | number) => {
    try {
      const idStr = typeof id === 'number' ? id.toString() : id;
      const result = await viewsMaxApi.deleteCopyThumbnail(idStr);

      if (result.success) {
        // Remove from unified state
        setThumbnails(prev => prev.filter(t => !(t.type === 'copied' && t.id === id)));
        toast.success("Copy thumbnail deleted successfully!");
      } else {
        throw new Error(result.error || 'Failed to delete copy thumbnail');
      }
    } catch (error) {
      console.error('Error deleting copy thumbnail:', error);
      toast.error("Failed to delete copy thumbnail");
    }
  };

  const updateThumbnail = async () => {
    if (!editingThumbnail) {
      toast.error("No thumbnail selected");
      return;
    }

    if (!editPrompt.trim()) {
      toast.error("Please enter a prompt");
      return;
    }

    const isCopyThumbnail = editingThumbnail.type === 'copied';

    setIsUpdatingThumbnail(true);
    try {
      const result = await viewsMaxApi.updateThumbnail(editingThumbnail.id, {
        prompt: editPrompt.trim()
      }, isCopyThumbnail ? 'copy' : undefined);

      if (result.success && result.data) {
        const updatedThumbnail = result.data;

        if (isCopyThumbnail) {
          // Backend creates a NEW copy thumbnail — add it to the list, keep original
          const newCopyThumbnail: Thumbnail = {
            ...updatedThumbnail,
            status: 'processing' as const
          };
          setThumbnails(prev => [newCopyThumbnail, ...prev]);
          toast.success("Thumbnail edit started!");
          pollCopyThumbnailStatus(updatedThumbnail.id);
        } else {
          // For generated thumbnails: existing logic
          const thumbnailIdToPoll = updatedThumbnail.id || editingThumbnail.id;
          const isNewChild = updatedThumbnail.id && updatedThumbnail.id !== editingThumbnail.id;

          if (isNewChild) {
            const newChildThumbnail: Thumbnail = {
              ...updatedThumbnail,
              status: 'pending' as const
            };
            setThumbnails(prev => [newChildThumbnail, ...prev]);
          } else {
            setThumbnails(prev =>
              prev.map(thumb =>
                thumb.id === editingThumbnail.id
                  ? { ...thumb, ...updatedThumbnail, status: 'pending' as const }
                  : thumb
              )
            );
          }
          toast.success("Thumbnail update started! Regenerating...");
          pollThumbnailStatus(thumbnailIdToPoll);
        }

        setEditPrompt("");
        setIsEditModalOpen(false);
        setEditingThumbnail(null);
      } else {
        toast.error(result.error || "Failed to update thumbnail");
      }
    } catch (error) {
      console.error('Error updating thumbnail:', error);
      toast.error("Failed to update thumbnail");
    } finally {
      setIsUpdatingThumbnail(false);
    }
  };

  const createModel = async () => {
    if (!newModelName.trim()) {
      toast.error("Please enter a model name");
      return;
    }

    if (modelImages.length < 10) {
      toast.error("Please upload at least 10 images for the model");
      return;
    }

    if (modelImages.length > 25) {
      toast.error("Please upload no more than 25 images for the model");
      return;
    }

    setIsCreatingModel(true);
    try {
      const result = await viewsMaxApi.createModel({
        name: newModelName,
        images: modelImages,
        age: age,
        type: typeId || undefined,
        ethnicity: ethnicityId || undefined,
        bald_shaved_head: baldShavedHead
      });

      if (result.success && result.data) {
        setModels(prev => [result.data!, ...prev]);
        
        // Add the new model to processing context
        addProcessingModel(result.data!);
        
        setNewModelName("");
        setModelImages([]);
        setAge(undefined);
        setTypeId("");
        setEthnicityId("");
        setBaldShavedHead(false);
        setIsModelModalOpen(false);
        toast.success("Model creation started! This may take several minutes.");
      } else {
        throw new Error(result.error || 'Failed to create model');
      }
    } catch (error) {
      console.error('Error creating model:', error);
      toast.error("Failed to create model. Please try again.");
    } finally {
      setIsCreatingModel(false);
    }
  };

  const handleImageUpload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const files = event.target.files;
    if (files) {
      const fileArray = Array.from(files);
      setModelImages(prev => [...prev, ...fileArray]);
    }
  };

  const removeImage = (index: number) => {
    setModelImages(prev => prev.filter((_, i) => i !== index));
  };

  const openModelModal = () => {
    setIsModelModalOpen(true);
  };

  const enhanceDescription = async () => {
    if (!newThumbnailDescription || typeof newThumbnailDescription !== 'string' || !newThumbnailDescription.trim()) {
      toast.error("Please enter a description to enhance");
      return;
    }

    setIsEnhancingDescription(true);
    try {
      const result = await viewsMaxApi.enhanceThumbnailDescription(newThumbnailDescription);
      
      if (result.success && result.data) {
        // Use the description field from the response data
        const enhancedDescription = typeof result.data === 'string' 
          ? result.data 
          : result.data.description || '';
        setNewThumbnailDescription(enhancedDescription);
        toast.success("Description enhanced successfully!");
      } else {
        throw new Error(result.error || 'Failed to enhance description');
      }
    } catch (error) {
      console.error('Error enhancing description:', error);
      toast.error("Failed to enhance description. Please try again.");
    } finally {
      setIsEnhancingDescription(false);
    }
  };

  // URL validation helper
  const isValidUrl = (url: string): boolean => {
    try {
      new URL(url);
      return true;
    } catch {
      return false;
    }
  };

  // Extract YouTube video ID from various YouTube URL formats
  const extractYouTubeVideoId = (url: string): string | null => {
    const patterns = [
      /(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/,
      /youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/,
      /youtube\.com\/v\/([a-zA-Z0-9_-]{11})/,
      /youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/
    ];
    
    for (const pattern of patterns) {
      const match = url.match(pattern);
      if (match && match[1]) {
        return match[1];
      }
    }
    return null;
  };

  // Get YouTube thumbnail URL from video ID with quality fallback
  const getYouTubeThumbnailUrl = async (videoId: string): Promise<string> => {
    // YouTube provides thumbnails at different qualities
    // Try from highest to lowest quality
    const qualities = [
      { name: 'maxresdefault', resolution: '1920x1080' },  // Highest quality, not always available
      { name: 'sddefault', resolution: '640x480' },        // Standard quality
      { name: 'hqdefault', resolution: '480x360' }         // High quality, guaranteed to exist
    ];
    
    // Try each quality until we find one that exists
    // Use Image object to avoid CORS issues (unlike fetch with HEAD)
    for (const quality of qualities) {
      const url = `https://img.youtube.com/vi/${videoId}/${quality.name}.jpg`;
      
      try {
        await new Promise((resolve, reject) => {
          const img = new Image();
          img.onload = () => resolve(true);
          img.onerror = () => reject(false);
          img.src = url;
        });
        // If we get here, the image loaded successfully
        return url;
      } catch {
        // Image failed to load, try next quality
        continue;
      }
    }
    
    // Fallback to hqdefault which is guaranteed to exist
    return `https://img.youtube.com/vi/${videoId}/hqdefault.jpg`;
  };

  // Validate if URL is a YouTube URL
  const isYouTubeUrl = (url: string): boolean => {
    return extractYouTubeVideoId(url) !== null;
  };

  // Copy thumbnail handlers
  const handleInspirationImageUpload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const files = event.target.files;
    if (files && files.length > 0) {
      setInspirationImage(files[0]);
      setInspirationImageUrl(""); // Clear URL if uploading
    }
  };

  const handleReferenceImageUpload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const files = event.target.files;
    if (files && files.length > 0) {
      setReferenceImage(files[0]);
    }
  };

  // Fetch user's default reference image when Copy tab becomes active
  const fetchUserDefaultReferenceImage = async () => {
    try {
      const result = await viewsMaxApi.getUserDefaultReferenceImage();
      if (result.success && result.data) {
        setUserDefaultReferenceImage(result.data);
      }
    } catch (error) {
      console.error('Error fetching default reference image:', error);
    }
  };

  // Upload new default reference image
  const [isUploadingDefault, setIsUploadingDefault] = useState(false);
  const [isDeletingDefault, setIsDeletingDefault] = useState(false);

  const handleUploadDefaultReferenceImage = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const files = event.target.files;
    if (!files || files.length === 0) return;

    setIsUploadingDefault(true);
    try {
      const result = await viewsMaxApi.uploadUserDefaultReferenceImage(files[0]);
      if (result.success && result.data) {
        setUserDefaultReferenceImage(result.data);
        toast.success("Default reference image uploaded successfully!");
      } else {
        throw new Error(result.error || 'Failed to upload default image');
      }
    } catch (error) {
      console.error('Error uploading default reference image:', error);
      const errorMessage = error instanceof Error ? error.message : "Failed to upload default reference image.";
      toast.error(errorMessage);
    } finally {
      setIsUploadingDefault(false);
      // Reset input
      event.target.value = '';
    }
  };

  const handleDeleteDefaultReferenceImage = async () => {
    setIsDeletingDefault(true);
    try {
      const result = await viewsMaxApi.deleteUserDefaultReferenceImage();
      if (result.success) {
        setUserDefaultReferenceImage({ has_default_image: false, image_url: null });
        toast.success("Default reference image removed successfully!");
      } else {
        throw new Error(result.error || 'Failed to delete default image');
      }
    } catch (error) {
      console.error('Error deleting default reference image:', error);
      const errorMessage = error instanceof Error ? error.message : "Failed to delete default reference image.";
      toast.error(errorMessage);
    } finally {
      setIsDeletingDefault(false);
    }
  };

  const copyThumbnail = async () => {
    // Validation - need either inspiration image file or YouTube URL
    const hasInspirationImage = inspirationImage || (inspirationImageUrl.trim() && isValidUrl(inspirationImageUrl.trim()));
    if (!hasInspirationImage) {
      toast.error("Please upload an inspiration image or enter a valid YouTube URL");
      return;
    }

    // If URL mode is selected, validate it's a YouTube URL
    if (!inspirationImage && inspirationImageUrl.trim()) {
      if (!isYouTubeUrl(inspirationImageUrl.trim())) {
        toast.error("Please enter a valid YouTube URL");
        return;
      }
    }
    
    // Reference image is optional if user has a default
    const hasReferenceImage = referenceImage || (userDefaultReferenceImage?.has_default_image);
    if (!hasReferenceImage) {
      toast.error("Please upload a reference image or set a default reference image");
      return;
    }

    setIsCopying(true);
    try {
      // Extract YouTube thumbnail URL if a YouTube URL was provided
      let finalBaseImageUrl = inspirationImageUrl.trim() || undefined;
      
      if (finalBaseImageUrl && isYouTubeUrl(finalBaseImageUrl)) {
        const videoId = extractYouTubeVideoId(finalBaseImageUrl);
        if (videoId) {
          finalBaseImageUrl = await getYouTubeThumbnailUrl(videoId);
        } else {
          toast.error("Failed to extract YouTube video ID");
          setIsCopying(false);
          return;
        }
      }

      const result = await viewsMaxApi.copyThumbnail({
        method: 'head_swap',
        quality: 'fast',
        baseImage: inspirationImage || undefined,
        baseImageUrl: finalBaseImageUrl,
        referenceImage: referenceImage || undefined,
        numberOfImages: 1
      });

      if (result.success && result.data) {
        // Create local preview URL for the inspiration image
        // This is needed because the backend POST response may not include base_image_url
        let localInspirationImageUrl = result.data.base_image_url || result.data.base_image_path;
        
        // If backend didn't return the URL, create one from the local file or use the extracted thumbnail URL
        if (!localInspirationImageUrl) {
          if (inspirationImage) {
            // Create a blob URL from the uploaded file for local preview
            localInspirationImageUrl = URL.createObjectURL(inspirationImage);
          } else if (finalBaseImageUrl) {
            // Use the extracted YouTube thumbnail URL (or provided URL)
            localInspirationImageUrl = finalBaseImageUrl;
          }
        }
        
        const newCopyThumbnail: Thumbnail = {
          ...result.data,
          type: 'copied',
          status: 'pending',
          base_image_url: localInspirationImageUrl,
          base_image_path: localInspirationImageUrl,
          created_at: result.data.created_at || new Date().toISOString(),
          updated_at: result.data.updated_at || new Date().toISOString()
        };
        setThumbnails(prev => [newCopyThumbnail, ...prev]);
        toast.success("Image generation started!");

        // Clear form
        setInspirationImage(null);
        setInspirationImageUrl("");
        setReferenceImage(null);

        // Start polling for status updates
        pollCopyThumbnailStatus(result.data.id);
      } else {
        throw new Error(result.error || 'Failed to generate image');
      }
    } catch (error) {
      console.error('Error generating image:', error);
      const errorMessage = error instanceof Error ? error.message : "Failed to generate image. Please try again.";
      toast.error(errorMessage);
    } finally {
      setIsCopying(false);
    }
  };

  const pollCopyThumbnailStatus = async (thumbnailId: string | number) => {
    // Check if already polling this thumbnail
    if (activePollsRef.current.has(thumbnailId)) {
      return;
    }

    // Mark as actively polling
    activePollsRef.current.add(thumbnailId);

    const pollInterval = 5000; // Poll every 5 seconds
    const maxAttempts = 60; // Maximum 5 minutes of polling
    let attempts = 0;
    let hasShownSuccessToast = false;

    const poll = async () => {
      try {
        const result = await viewsMaxApi.getCopyThumbnailStatus(thumbnailId);

        if (result.success && result.data) {
          const updatedThumbnail = result.data;

          // Update the copy thumbnail in the unified list, preserving important fields
          setThumbnails(prev => {
            const updated = prev.map(thumb => {
              if (thumb.type === 'copied' && thumb.id === thumbnailId) {
                const definedUpdates = Object.fromEntries(
                  Object.entries(updatedThumbnail).filter(([_, v]) => v !== undefined && v !== null)
                );

                const result = { ...thumb, ...definedUpdates };

                if (thumb.base_image_url && !result.base_image_url) {
                  result.base_image_url = thumb.base_image_url;
                }
                if (thumb.base_image_path && !result.base_image_path) {
                  result.base_image_path = thumb.base_image_path;
                }

                return result;
              }
              return thumb;
            });
            return updated;
          });

          // Check if processing is complete with image
          if (updatedThumbnail.status === 'completed') {
            // Check if image path is present
            const hasImage = updatedThumbnail.result_image_path ||
                           updatedThumbnail.result_image_paths?.[0] ||
                           updatedThumbnail.image_url ||
                           updatedThumbnail.image_path;

            if (hasImage) {
              // Only show success toast if we haven't shown it yet
              if (!hasShownSuccessToast) {
                toast.success("Copy thumbnail generated successfully!");
                hasShownSuccessToast = true;
              }
              // Remove from active polls and stop polling
              activePollsRef.current.delete(thumbnailId);
              return;
            }
            // Status is completed but no image yet - continue polling
          } else if (updatedThumbnail.status === 'failed') {
            toast.error("Copy thumbnail generation failed!");
            activePollsRef.current.delete(thumbnailId);
            return; // Stop polling
          }
        }

        // Continue polling if not complete and under max attempts
        attempts++;
        if (attempts < maxAttempts) {
          setTimeout(poll, pollInterval);
        } else {
          toast.error("Copy thumbnail generation timed out!");
          activePollsRef.current.delete(thumbnailId);
        }
      } catch (error) {
        console.error('Error polling copy thumbnail status:', error);
        attempts++;
        if (attempts < maxAttempts) {
          setTimeout(poll, pollInterval);
        } else {
          toast.error("Failed to check copy thumbnail status!");
          activePollsRef.current.delete(thumbnailId);
        }
      }
    };

    // Start polling after a short delay
    setTimeout(poll, pollInterval);
  };

  if (isLoading) {
    return (
      <div className="space-y-6 max-w-6xl">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">Loading thumbnails...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-6xl">
      {/* Tab Navigation */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList className="mb-4">
          <TabsTrigger value="create" className="gap-2">
            <Wand2 className="w-4 h-4" />
            Create Thumbnail
          </TabsTrigger>
          <TabsTrigger value="copy" className="gap-2">
            <Copy className="w-4 h-4" />
            Copy
          </TabsTrigger>
        </TabsList>

        {/* Create Thumbnail Tab */}
        <TabsContent value="create" className="space-y-6">
          {/* Generate New Thumbnail */}
          <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Wand2 className="w-5 h-5 text-primary" />
            Generate New Thumbnail
          </CardTitle>
          <CardDescription>
            Use AI to create compelling thumbnails for your videos
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="thumbnail-description">Description <span className="text-destructive">*</span></Label>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={enhanceDescription}
                disabled={isEnhancingDescription || !newThumbnailDescription || typeof newThumbnailDescription !== 'string' || !newThumbnailDescription.trim()}
                className="gap-2"
              >
                {isEnhancingDescription ? (
                  <>
                    <Loader2 className="w-3 h-3 animate-spin" />
                    Enhancing...
                  </>
                ) : (
                  <>
                    <Sparkles className="w-3 h-3" />
                    Enhance
                  </>
                )}
              </Button>
            </div>
            <Textarea
              id="thumbnail-description"
              value={newThumbnailDescription}
              onChange={(e) => setNewThumbnailDescription(e.target.value)}
              placeholder="Describe what you want in your thumbnail..."
              className={`min-h-[100px] ${!newThumbnailDescription || typeof newThumbnailDescription !== 'string' || !newThumbnailDescription.trim() ? 'border-red-500' : ''}`}
              required
            />
          </div>
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              <Label htmlFor="model-selection">AI Model (Optional)</Label>
              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button variant="ghost" size="sm" className="h-4 w-4 p-0">
                      <span className="sr-only">Help</span>
                      <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                      </svg>
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    <p>Use an AI-generated model to create thumbnails featuring a specific person</p>
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
            </div>
            <div className="space-y-3">
              {/* AI Models Grid */}
              <div className="space-y-2">
                <div className="grid grid-cols-5 gap-3 max-h-80 overflow-y-auto">
                  {/* Create New Model Button */}
                  <Button
                    type="button"
                    variant="outline"
                    onClick={openModelModal}
                    className="flex flex-col items-center gap-2 p-3 h-auto"
                  >
                    <div className="w-24 h-24 bg-muted rounded flex items-center justify-center">
                      <Plus className="w-12 h-12" />
                    </div>
                    <span className="text-xs text-center line-clamp-2">Create New A.I model</span>
                  </Button>

                  {/* Existing Models */}
                  {Array.isArray(models) && models.filter(model => model.status === 'completed').map((model) => (
                    <Button
                      key={model.id}
                      type="button"
                      variant={selectedModelId === model.id.toString() ? "default" : "outline"}
                      onClick={() => {
                        // Toggle selection: if already selected, unselect it
                        if (selectedModelId === model.id.toString()) {
                          setSelectedModelId("");
                        } else {
                          setSelectedModelId(model.id.toString());
                        }
                      }}
                      className="flex flex-col items-center gap-2 p-3 h-auto"
                    >
                      {model.thumbnail_image ? (
                        <img 
                          src={model.thumbnail_image} 
                          alt={model.name}
                          className="w-24 h-24 rounded object-cover"
                        />
                      ) : (
                        <div className="w-24 h-24 bg-muted rounded flex items-center justify-center">
                          <User className="w-12 h-12" />
                        </div>
                      )}
                      <span className="text-xs text-center line-clamp-2">{model.name}</span>
                    </Button>
                  ))}
                </div>
              </div>

              {Array.isArray(models) && models.filter(model => model.status === 'completed').length === 0 && (
                <div className="text-center py-2 text-muted-foreground">
                  <p className="text-xs">No completed AI models available</p>
                </div>
              )}
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="thumbnail-quantity">How Many? *</Label>
            <Select value={thumbnailQuantity} onValueChange={setThumbnailQuantity}>
              <SelectTrigger className={!thumbnailQuantity ? 'border-red-500' : ''}>
                <SelectValue placeholder="How Many?" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="1">1</SelectItem>
                <SelectItem value="3">3</SelectItem>
                <SelectItem value="6">6</SelectItem>
                <SelectItem value="9">9</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="flex justify-end">
            <Button
              onClick={generateThumbnail}
              disabled={isGenerating || !thumbnailQuantity || !newThumbnailDescription || typeof newThumbnailDescription !== 'string' || !newThumbnailDescription.trim()}
              className="gap-2"
            >
              <Wand2 className="w-4 h-4" />
              {isGenerating ? "Generating..." : `Generate ${thumbnailQuantity || '0'} Thumbnail${thumbnailQuantity && parseInt(thumbnailQuantity) > 1 ? 's' : ''}`}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Thumbnails Grid - Generated thumbnails only */}
      {(() => {
        const allGeneratedThumbnails = thumbnails.filter(t => t.type !== 'copied');
        const totalGeneratePages = Math.ceil(allGeneratedThumbnails.length / COPY_PER_PAGE);
        const paginatedGeneratedThumbnails = allGeneratedThumbnails.slice((generatePage - 1) * COPY_PER_PAGE, generatePage * COPY_PER_PAGE);
        
        if (allGeneratedThumbnails.length === 0) return null;
        
        return (
          <>
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {paginatedGeneratedThumbnails.map((thumbnail) => {
          const allVersions = getAllVersions(thumbnail);
          const hasParents = allVersions.length > 1;
          const currentIndex = thumbnailImageIndex[thumbnail.id] || 0;
          const currentThumbnail = allVersions[currentIndex] || thumbnail;

          const handlePrevious = () => {
            if (!hasParents) return;
            const newIndex = Math.max(0, currentIndex - 1);
            setThumbnailImageIndex(prev => ({
              ...prev,
              [thumbnail.id]: newIndex
            }));
          };

          const handleNext = () => {
            if (!hasParents) return;
            const newIndex = Math.min(allVersions.length - 1, currentIndex + 1);
            setThumbnailImageIndex(prev => ({
              ...prev,
              [thumbnail.id]: newIndex
            }));
          };

          const handleJumpToGrandparent = () => {
            if (!hasParents) return;
            // Jump to the highest nested grandparent (last index)
            const lastIndex = allVersions.length - 1;
            setThumbnailImageIndex(prev => ({
              ...prev,
              [thumbnail.id]: lastIndex
            }));
          };

          const handleJumpToInitial = () => {
            if (!hasParents) return;
            // Jump back to the initial image (index 0)
            setThumbnailImageIndex(prev => ({
              ...prev,
              [thumbnail.id]: 0
            }));
          };

          return (
            <Card key={`generated-${thumbnail.id}`} className="overflow-hidden">
              <div 
                className="aspect-video bg-muted flex items-center justify-center relative"
              >
                {hasParents && (
                  <>
                    {/* Left Arrow - shown on initial image to jump to highest grandparent, or on other images to go previous */}
                    {currentIndex === 0 ? (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="absolute left-2 top-1/2 -translate-y-1/2 z-10 h-8 w-8 bg-background/80 backdrop-blur-sm hover:bg-background/90 shadow-lg"
                        onClick={handleJumpToGrandparent}
                      >
                        <ChevronLeft className="w-4 h-4" />
                      </Button>
                    ) : (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="absolute left-2 top-1/2 -translate-y-1/2 z-10 h-8 w-8 bg-background/80 backdrop-blur-sm hover:bg-background/90 shadow-lg"
                        onClick={handlePrevious}
                      >
                        <ChevronLeft className="w-4 h-4" />
                      </Button>
                    )}
                    {/* Right Arrow - shown on highest grandparent to jump back to initial, or on other images to go next */}
                    {currentIndex === allVersions.length - 1 ? (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="absolute right-2 top-1/2 -translate-y-1/2 z-10 h-8 w-8 bg-background/80 backdrop-blur-sm hover:bg-background/90 shadow-lg"
                        onClick={handleJumpToInitial}
                      >
                        <ChevronRight className="w-4 h-4" />
                      </Button>
                    ) : currentIndex < allVersions.length - 1 ? (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="absolute right-2 top-1/2 -translate-y-1/2 z-10 h-8 w-8 bg-background/80 backdrop-blur-sm hover:bg-background/90 shadow-lg"
                        onClick={handleNext}
                      >
                        <ChevronRight className="w-4 h-4" />
                      </Button>
                    ) : null}
                  </>
                )}
                {thumbnail.status === 'failed' ? (
                  <div className="flex flex-col items-center justify-center space-y-2">
                    <div className="w-8 h-8 rounded-full bg-destructive/10 flex items-center justify-center">
                      <span className="text-destructive text-lg">!</span>
                    </div>
                    <p className="text-sm text-destructive">Generation failed</p>
                  </div>
                ) : (currentThumbnail.file_location || currentThumbnail.image_url) ? (
                  <img
                    src={currentThumbnail.file_location || currentThumbnail.image_url}
                    alt={currentThumbnail.description || 'Generated thumbnail'}
                    className="w-full h-full object-cover"
                  />
                ) : (
                  <div className="flex flex-col items-center justify-center space-y-2">
                    <Loader2 className="w-8 h-8 animate-spin text-primary" />
                    <p className="text-sm text-muted-foreground">
                      {currentThumbnail.status === 'pending' ? 'Processing...' : 
                       currentThumbnail.status === 'processed' ? 'Finalizing...' : 
                       'Generating...'}
                    </p>
                    {(() => {
                      const key = `${thumbnail.id}-${currentThumbnail.id}`;
                      const seconds = elapsedSeconds[key] || 0;
                      return seconds > 0 && (
                        <p className="text-xs text-muted-foreground">
                          {seconds}s
                        </p>
                      );
                    })()}
                  </div>
                )}
              </div>
            <CardContent className="p-4">
              <div className="space-y-2">
                {thumbnail.description && (
                  <p className="text-sm text-muted-foreground line-clamp-2">
                    {thumbnail.description}
                  </p>
                )}
                <div className="flex items-center justify-between text-xs text-muted-foreground">
                  <span>
                    {new Date(thumbnail.created_at).toLocaleDateString()}
                  </span>
                  <div className="flex gap-1">
                    {(currentThumbnail.file_location || currentThumbnail.image_url) && (
                      <>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-6 w-6 p-0"
                          onClick={() => {
                            setModalImageIndex(0);
                            openThumbnailModal(currentThumbnail);
                          }}
                        >
                          <Eye className="w-3 h-3" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-6 w-6 p-0"
                          onClick={() => openEditModal(currentThumbnail)}
                        >
                          <Edit className="w-3 h-3" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-6 w-6 p-0"
                          onClick={() => downloadThumbnail(currentThumbnail.id)}
                        >
                          <Download className="w-3 h-3" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-6 w-6 p-0"
                          onClick={() => duplicateThumbnail(thumbnail)}
                          title="Duplicate description"
                        >
                          <Copy className="w-3 h-3" />
                        </Button>
                      </>
                    )}
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-6 w-6 p-0 text-destructive hover:text-destructive"
                      onClick={() => deleteThumbnail(thumbnail.id)}
                    >
                      <Trash2 className="w-3 h-3" />
                    </Button>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
          );
        })}
      </div>
      {totalGeneratePages > 1 && (
        <div className="flex items-center justify-center gap-4 mt-6">
          <Button
            variant="outline"
            size="sm"
            onClick={() => setGeneratePage(p => Math.max(1, p - 1))}
            disabled={generatePage === 1}
          >
            <ChevronLeft className="w-4 h-4 mr-1" />
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">
            Page {generatePage} of {totalGeneratePages} ({allGeneratedThumbnails.length} total)
          </span>
          <Button
            variant="outline"
            size="sm"
            onClick={() => setGeneratePage(p => Math.min(totalGeneratePages, p + 1))}
            disabled={generatePage === totalGeneratePages}
          >
            Next
            <ChevronRight className="w-4 h-4 ml-1" />
          </Button>
        </div>
      )}
          </>
        );
      })()}

      {thumbnails.filter(t => t.type !== 'copied').length === 0 && (
        <Card className="text-center py-12">
          <CardContent>
            <ImageIcon className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
            <h3 className="text-lg font-semibold mb-2">No thumbnails yet</h3>
            <p className="text-muted-foreground">
              Generate your first AI-powered thumbnail to get started
            </p>
          </CardContent>
        </Card>
      )}
        </TabsContent>

        {/* Copy Tab */}
        <TabsContent value="copy" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Copy className="w-5 h-5 text-primary" />
                Copy Thumbnail Style
              </CardTitle>
              <CardDescription>
                Create a new thumbnail by swapping faces using inspiration and reference images
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Inspiration Image - File or URL */}
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label>
                    {inspirationInputMode === "upload" ? "Inspiration Image" : "Inspiration YouTube Video"}
                    {" "}<span className="text-destructive">*</span>
                  </Label>
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      variant={inspirationInputMode === "upload" ? "default" : "outline"}
                      size="sm"
                      onClick={() => setInspirationInputMode("upload")}
                    >
                      <Upload className="w-4 h-4 mr-1" />
                      Upload
                    </Button>
                    <Button
                      type="button"
                      variant={inspirationInputMode === "url" ? "default" : "outline"}
                      size="sm"
                      onClick={() => setInspirationInputMode("url")}
                    >
                      <Link className="w-4 h-4 mr-1" />
                      URL
                    </Button>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground">
                  {inspirationInputMode === "upload" 
                    ? "The source image for the thumbnail style" 
                    : "The YouTube video to extract the thumbnail from"}
                </p>
                
                {inspirationInputMode === "upload" ? (
                  <div className="border-2 border-dashed border-border rounded-lg p-6">
                    {inspirationImage ? (
                      <div className="relative">
                        <img 
                          src={URL.createObjectURL(inspirationImage)} 
                          alt="Inspiration image preview"
                          className="max-h-48 mx-auto rounded-lg object-contain"
                        />
                        <Button
                          variant="destructive"
                          size="sm"
                          className="absolute top-2 right-2"
                          onClick={() => setInspirationImage(null)}
                        >
                          <X className="w-4 h-4" />
                        </Button>
                      </div>
                    ) : (
                      <label className="flex flex-col items-center justify-center cursor-pointer">
                        <Upload className="w-10 h-10 text-muted-foreground mb-2" />
                        <span className="text-sm text-muted-foreground">Click to upload or drag and drop</span>
                        <span className="text-xs text-muted-foreground mt-1">PNG, JPG, WebP up to 10MB</span>
                        <input
                          type="file"
                          accept="image/png,image/jpeg,image/webp"
                          className="hidden"
                          onChange={handleInspirationImageUpload}
                        />
                      </label>
                    )}
                  </div>
                ) : (
                  <div className="relative">
                    <Link className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                    <Input
                      type="url"
                      value={inspirationImageUrl}
                      onChange={(e) => setInspirationImageUrl(e.target.value)}
                      placeholder="https://www.youtube.com/watch?v=dQw4w9WgXcQ"
                      className="pl-10"
                    />
                  </div>
                )}
              </div>

              {/* Reference Image Upload */}
              <div className="space-y-2">
                <Label>
                  Reference Image 
                  {userDefaultReferenceImage?.has_default_image ? (
                    <span className="text-muted-foreground ml-1">(Optional - using default)</span>
                  ) : (
                    <span className="text-destructive ml-1">*</span>
                  )}
                </Label>
                <p className="text-sm text-muted-foreground">
                  The face to use in the generated thumbnail
                  {userDefaultReferenceImage?.has_default_image && (
                    <span className="text-green-600 ml-1">- You have a default reference image set</span>
                  )}
                </p>
                
                {/* Show user's default reference image if available */}
                {userDefaultReferenceImage?.has_default_image && userDefaultReferenceImage.image_url && !referenceImage && (
                  <div className="bg-muted/50 rounded-lg p-4">
                    <div className="flex items-center gap-4">
                      <img 
                        src={userDefaultReferenceImage.image_url} 
                        alt="Default reference"
                        className="w-20 h-20 rounded object-cover"
                      />
                      <div className="flex-1">
                        <p className="text-sm font-medium">Current Default Reference Image</p>
                        <p className="text-xs text-muted-foreground mb-2">This will be used if you don't upload a new one</p>
                        <div className="flex gap-2">
                          <label>
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              disabled={isUploadingDefault}
                              className="gap-1"
                              asChild
                            >
                              <span>
                                {isUploadingDefault ? (
                                  <>
                                    <Loader2 className="w-3 h-3 animate-spin" />
                                    Uploading...
                                  </>
                                ) : (
                                  <>
                                    <Upload className="w-3 h-3" />
                                    Replace
                                  </>
                                )}
                              </span>
                            </Button>
                            <input
                              type="file"
                              accept="image/png,image/jpeg,image/webp"
                              className="hidden"
                              onChange={handleUploadDefaultReferenceImage}
                              disabled={isUploadingDefault}
                            />
                          </label>
                          <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            onClick={handleDeleteDefaultReferenceImage}
                            disabled={isDeletingDefault}
                            className="gap-1"
                          >
                            {isDeletingDefault ? (
                              <>
                                <Loader2 className="w-3 h-3 animate-spin" />
                                Removing...
                              </>
                            ) : (
                              <>
                                <X className="w-3 h-3" />
                                Remove
                              </>
                            )}
                          </Button>
                        </div>
                      </div>
                    </div>
                  </div>
                )}
                
                {/* Option to set a default if none exists */}
                {!userDefaultReferenceImage?.has_default_image && (
                  <div className="bg-yellow-50 dark:bg-yellow-950/20 border border-yellow-200 dark:border-yellow-900 rounded-lg p-4">
                    <div className="flex items-start gap-3">
                      <div className="flex-1">
                        <p className="text-sm font-medium text-yellow-800 dark:text-yellow-200">No Default Reference Image Set</p>
                        <p className="text-xs text-yellow-700 dark:text-yellow-300 mb-2">Set a default to use it across all copy thumbnail requests</p>
                        <label>
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={isUploadingDefault}
                            className="gap-1"
                            asChild
                          >
                            <span>
                              {isUploadingDefault ? (
                                <>
                                  <Loader2 className="w-3 h-3 animate-spin" />
                                  Uploading...
                                </>
                              ) : (
                                <>
                                  <Upload className="w-3 h-3" />
                                  Set Default Reference Image
                                </>
                              )}
                            </span>
                          </Button>
                          <input
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            className="hidden"
                            onChange={handleUploadDefaultReferenceImage}
                            disabled={isUploadingDefault}
                          />
                        </label>
                      </div>
                    </div>
                  </div>
                )}
                
                <div className="border-2 border-dashed border-border rounded-lg p-6">
                  {referenceImage ? (
                    <div className="relative">
                      <img 
                        src={URL.createObjectURL(referenceImage)} 
                        alt="Reference image preview"
                        className="max-h-48 mx-auto rounded-lg object-contain"
                      />
                      <Button
                        variant="destructive"
                        size="sm"
                        className="absolute top-2 right-2"
                        onClick={() => setReferenceImage(null)}
                      >
                        <X className="w-4 h-4" />
                      </Button>
                    </div>
                  ) : (
                    <label className="flex flex-col items-center justify-center cursor-pointer">
                      <Upload className="w-10 h-10 text-muted-foreground mb-2" />
                      <span className="text-sm text-muted-foreground">Click to upload or drag and drop</span>
                      <span className="text-xs text-muted-foreground mt-1">PNG, JPG, WebP up to 10MB</span>
                      <input
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={handleReferenceImageUpload}
                      />
                    </label>
                  )}
                </div>
              </div>

              {/* Submit Button */}
              <div className="flex justify-end">
                <Button
                  onClick={copyThumbnail}
                  disabled={isCopying || 
                    !(inspirationImage || (inspirationImageUrl.trim() && isYouTubeUrl(inspirationImageUrl.trim()))) || 
                    !(referenceImage || userDefaultReferenceImage?.has_default_image)}
                  className="gap-2"
                >
                  {isCopying ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      Processing...
                    </>
                  ) : (
                    <>
                      <Copy className="w-4 h-4" />
                      Copy Thumbnail
                    </>
                  )}
                </Button>
              </div>
            </CardContent>
          </Card>

          {/* Copy Thumbnails Results */}
          {(() => {
            const allCopyThumbnails = thumbnails.filter(t => t.type === 'copied');
            const totalCopyPages = Math.ceil(allCopyThumbnails.length / COPY_PER_PAGE);
            const paginatedCopyThumbnails = allCopyThumbnails.slice((copyPage - 1) * COPY_PER_PAGE, copyPage * COPY_PER_PAGE);
            
            if (allCopyThumbnails.length === 0) return null;
            
            return (
              <>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              {paginatedCopyThumbnails.map((copyThumb) => (
                <Card key={`copied-${copyThumb.id}`}>
                  <CardContent className="p-4">
                    {copyThumb.status === 'pending' || copyThumb.status === 'processed' || copyThumb.status === 'processing' ? (
                      <div className="aspect-video bg-muted rounded-lg flex items-center justify-center">
                        <div className="text-center">
                          <Loader2 className="w-8 h-8 animate-spin mx-auto mb-2" />
                          <p className="text-sm text-muted-foreground">Processing...</p>
                        </div>
                      </div>
                    ) : copyThumb.status === 'completed' && (copyThumb.image_url || copyThumb.result_image_url || copyThumb.result_image_path || copyThumb.result_image_paths?.[0]) ? (
                      <div className="aspect-video bg-muted rounded-lg overflow-hidden">
                        <img 
                          src={(() => {
                            // Prefer image_url/result_image_url (already has /storage/ prefix from backend accessor)
                            const urlField = copyThumb.image_url || copyThumb.result_image_url;
                            if (urlField) {
                              if (urlField.startsWith('http://') || urlField.startsWith('https://') || urlField.startsWith('blob:')) {
                                return urlField;
                              }
                              return `${API_BASE_URL}${urlField.startsWith('/') ? '' : '/'}${urlField}`;
                            }
                            // Fallback to raw path
                            const path = copyThumb.result_image_path || copyThumb.result_image_paths?.[0] || '';
                            if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('blob:')) {
                              return path;
                            }
                            return `${API_BASE_URL}/storage/${path}`;
                          })()} 
                          alt="Generated thumbnail"
                          className="w-full h-full object-contain"
                        />
                      </div>
                    ) : copyThumb.status === 'failed' ? (
                      <div className="aspect-video bg-red-50 rounded-lg flex items-center justify-center">
                        <div className="text-center">
                          <X className="w-8 h-8 text-red-500 mx-auto mb-2" />
                          <p className="text-sm text-red-500">Generation failed</p>
                        </div>
                      </div>
                    ) : null}
                    <div className="flex items-center justify-between text-xs text-muted-foreground pt-2">
                      <span>
                        {copyThumb.created_at
                          ? new Date(copyThumb.created_at).toLocaleDateString()
                          : 'Just now'}
                      </span>
                      <div className="flex gap-1">
                        {copyThumb.status === 'completed' && (copyThumb.image_url || copyThumb.result_image_url || copyThumb.result_image_path || copyThumb.result_image_paths?.[0]) && (
                          <>
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-6 w-6 p-0"
                              onClick={() => openCopyThumbnailModal(copyThumb)}
                            >
                              <Eye className="w-3 h-3" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-6 w-6 p-0"
                              onClick={() => openEditModal(copyThumb)}
                              title="Edit with prompt"
                            >
                              <Edit className="w-3 h-3" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-6 w-6 p-0"
                              onClick={() => downloadCopyThumbnail(copyThumb.id)}
                            >
                              <Download className="w-3 h-3" />
                            </Button>
                          </>
                        )}
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-6 w-6 p-0 text-destructive hover:text-destructive"
                          onClick={() => deleteCopyThumbnail(copyThumb.id)}
                        >
                          <Trash2 className="w-3 h-3" />
                        </Button>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              ))}
              </div>
              {totalCopyPages > 1 && (
                <div className="flex items-center justify-center gap-4 mt-6">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCopyPage(p => Math.max(1, p - 1))}
                    disabled={copyPage === 1}
                  >
                    <ChevronLeft className="w-4 h-4 mr-1" />
                    Previous
                  </Button>
                  <span className="text-sm text-muted-foreground">
                    Page {copyPage} of {totalCopyPages} ({allCopyThumbnails.length} total)
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCopyPage(p => Math.min(totalCopyPages, p + 1))}
                    disabled={copyPage === totalCopyPages}
                  >
                    Next
                    <ChevronRight className="w-4 h-4 ml-1" />
                  </Button>
                </div>
              )}
              </>
            );
          })()}

          {thumbnails.filter(t => t.type === 'copied').length === 0 && (
            <Card className="text-center py-12">
              <CardContent>
                <Copy className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
                <h3 className="text-lg font-semibold mb-2">No copy thumbnails yet</h3>
                <p className="text-muted-foreground">
                  Upload images and provide an inspiration link to get started
                </p>
              </CardContent>
            </Card>
          )}
        </TabsContent>
      </Tabs>

      {/* Thumbnail Preview Modal */}
      <Dialog open={isModalOpen} onOpenChange={setIsModalOpen}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>
              Thumbnail Preview
            </DialogTitle>
            <DialogDescription>
              View and download your generated thumbnail with detailed information
            </DialogDescription>
          </DialogHeader>
          
          {selectedThumbnail && (() => {
            const allVersions = getAllVersions(selectedThumbnail);
            const hasParents = allVersions.length > 1;
            const currentIndex = modalImageIndex;
            const currentThumbnail = allVersions[currentIndex] || selectedThumbnail;

            const handleModalPrevious = () => {
              if (!hasParents) return;
              const newIndex = Math.max(0, currentIndex - 1);
              setModalImageIndex(newIndex);
            };

            const handleModalNext = () => {
              if (!hasParents) return;
              const newIndex = Math.min(allVersions.length - 1, currentIndex + 1);
              setModalImageIndex(newIndex);
            };

            const handleModalJumpToGrandparent = () => {
              if (!hasParents) return;
              // Jump to the highest nested grandparent (last index)
              const lastIndex = allVersions.length - 1;
              setModalImageIndex(lastIndex);
            };

            const handleModalJumpToInitial = () => {
              if (!hasParents) return;
              // Jump back to the initial image (index 0)
              setModalImageIndex(0);
            };

            return (
              <div className="space-y-6">
                {/* Image Preview */}
                <div className="flex justify-center">
                  <div 
                    className="relative max-w-full"
                  >
                    {hasParents && (
                      <>
                        {/* Left Arrow - shown on initial image to jump to highest grandparent, or on other images to go previous */}
                        {currentIndex === 0 ? (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="absolute left-2 top-1/2 -translate-y-1/2 z-10 h-10 w-10 bg-background/80 backdrop-blur-sm hover:bg-background/90 shadow-lg"
                            onClick={handleModalJumpToGrandparent}
                          >
                            <ChevronLeft className="w-5 h-5" />
                          </Button>
                        ) : (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="absolute left-2 top-1/2 -translate-y-1/2 z-10 h-10 w-10 bg-background/80 backdrop-blur-sm hover:bg-background/90 shadow-lg"
                            onClick={handleModalPrevious}
                          >
                            <ChevronLeft className="w-5 h-5" />
                          </Button>
                        )}
                        {/* Right Arrow - shown on highest grandparent to jump back to initial, or on other images to go next */}
                        {currentIndex === allVersions.length - 1 ? (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="absolute right-2 top-1/2 -translate-y-1/2 z-10 h-10 w-10 bg-background/80 backdrop-blur-sm hover:bg-background/90 shadow-lg"
                            onClick={handleModalJumpToInitial}
                          >
                            <ChevronRight className="w-5 h-5" />
                          </Button>
                        ) : currentIndex < allVersions.length - 1 ? (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="absolute right-2 top-1/2 -translate-y-1/2 z-10 h-10 w-10 bg-background/80 backdrop-blur-sm hover:bg-background/90 shadow-lg"
                            onClick={handleModalNext}
                          >
                            <ChevronRight className="w-5 h-5" />
                          </Button>
                        ) : null}
                      </>
                    )}
                    <img
                      src={currentThumbnail.file_location || currentThumbnail.image_url}
                      alt={currentThumbnail.description || 'Generated thumbnail'}
                      className="max-w-full max-h-[60vh] object-contain rounded-lg border"
                    />
                  </div>
                </div>
              
                {/* Thumbnail Details */}
                <div className="space-y-4">
                  {currentThumbnail.description && (
                    <div>
                      <Label className="text-sm font-medium text-muted-foreground">Description</Label>
                      <p className="text-sm mt-1">{currentThumbnail.description}</p>
                    </div>
                  )}
                  
                  {currentThumbnail.visualizable_scene && (
                    <div>
                      <Label className="text-sm font-medium text-muted-foreground">Visualizable Scene</Label>
                      <div className="mt-1 p-3 bg-muted rounded-lg">
                        <p className="text-sm whitespace-pre-wrap">{currentThumbnail.visualizable_scene}</p>
                      </div>
                    </div>
                  )}
                  
                  {currentThumbnail.combined_prompts && (
                    <div>
                      <Label className="text-sm font-medium text-muted-foreground">Combined Prompts</Label>
                      <div className="mt-1 p-3 bg-muted rounded-lg">
                        <p className="text-sm whitespace-pre-wrap">{currentThumbnail.combined_prompts}</p>
                      </div>
                    </div>
                  )}
                  
                  <div className="flex items-center justify-between text-xs text-muted-foreground">
                    <span>Created: {new Date(currentThumbnail.created_at).toLocaleDateString()}</span>
                    <span>Status: {currentThumbnail.status}</span>
                  </div>
                </div>
              </div>
            );
          })()}
        </DialogContent>
      </Dialog>

      {/* Model Creation Modal */}
      <Dialog open={isModelModalOpen} onOpenChange={setIsModelModalOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <User className="w-5 h-5 text-primary" />
              Create AI Model
            </DialogTitle>
            <DialogDescription>
              Upload 10-25 images to create a custom AI model for generating thumbnails
            </DialogDescription>
          </DialogHeader>
          
          <div className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="model-name">Model Name <span className="text-destructive">*</span></Label>
              <Input
                id="model-name"
                value={newModelName}
                onChange={(e) => setNewModelName(e.target.value)}
                placeholder="Enter a name for your model..."
                className={!newModelName.trim() ? 'border-red-500' : ''}
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="age">Age</Label>
              <Select
                value={age?.toString() || ""}
                onValueChange={(value) => setAge(value ? parseInt(value) : undefined)}
              >
                <SelectTrigger id="age">
                  <SelectValue placeholder="Select age" />
                </SelectTrigger>
                <SelectContent>
                  {Array.from({ length: 83 }, (_, i) => i + 18).map((ageValue) => (
                    <SelectItem key={ageValue} value={ageValue.toString()}>
                      {ageValue}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="type">Type</Label>
              <Select
                value={typeId}
                onValueChange={setTypeId}
                disabled={isLoadingTypes}
              >
                <SelectTrigger id="type">
                  <SelectValue placeholder={isLoadingTypes ? "Loading types..." : "Select type"} />
                </SelectTrigger>
                <SelectContent>
                  {types.map((typeOption, index) => (
                    <SelectItem key={`type-${index}-${typeOption.id}`} value={String(typeOption.id)}>
                      {typeOption.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="ethnicity">Ethnicity</Label>
              <Select
                value={ethnicityId}
                onValueChange={setEthnicityId}
                disabled={isLoadingEthnicities}
              >
                <SelectTrigger id="ethnicity">
                  <SelectValue placeholder={isLoadingEthnicities ? "Loading ethnicities..." : "Select ethnicity"} />
                </SelectTrigger>
                <SelectContent>
                  {ethnicities.map((ethnicityOption, index) => (
                    <SelectItem key={`ethnicity-${index}-${ethnicityOption.id}`} value={String(ethnicityOption.id)}>
                      {ethnicityOption.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="bald-shaved-head"
                  checked={baldShavedHead}
                  onCheckedChange={(checked) => setBaldShavedHead(checked === true)}
                />
                <Label htmlFor="bald-shaved-head" className="cursor-pointer">
                  Bald / Shaved Head
                </Label>
              </div>
            </div>

            <div className="space-y-2">
              <Label>Upload Images <span className="text-destructive">*</span></Label>
              <div className="border-2 border-dashed border-muted-foreground/25 rounded-lg p-6 text-center">
                <input
                  type="file"
                  multiple
                  accept="image/*"
                  onChange={handleImageUpload}
                  className="hidden"
                  id="model-images"
                />
                <label htmlFor="model-images" className="cursor-pointer">
                  <Upload className="w-8 h-8 mx-auto mb-2 text-muted-foreground" />
                  <p className="text-sm text-muted-foreground">
                    Click to upload images (10-25 images required)
                  </p>
                  <p className="text-xs text-muted-foreground mt-1">
                    Current: {modelImages.length} images
                  </p>
                </label>
              </div>
              
              {modelImages.length > 0 && (
                <div className="space-y-2">
                  <p className="text-sm font-medium">Uploaded Images:</p>
                  <div className="grid grid-cols-4 gap-2 max-h-32 overflow-y-auto">
                    {modelImages.map((file, index) => (
                      <div key={index} className="relative">
                        <img
                          src={URL.createObjectURL(file)}
                          alt={`Preview ${index + 1}`}
                          className="w-full h-16 object-cover rounded border"
                        />
                        <Button
                          variant="destructive"
                          size="sm"
                          className="absolute -top-1 -right-1 h-5 w-5 p-0"
                          onClick={() => removeImage(index)}
                        >
                          <X className="w-3 h-3" />
                        </Button>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>

            <div className="flex justify-end gap-2">
              <Button
                variant="outline"
                onClick={() => setIsModelModalOpen(false)}
                disabled={isCreatingModel}
              >
                Cancel
              </Button>
              <Button
                onClick={createModel}
                disabled={isCreatingModel || !newModelName.trim() || modelImages.length < 10}
                className="gap-2"
              >
                {isCreatingModel ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Creating...
                  </>
                ) : (
                  <>
                    <User className="w-4 h-4" />
                    Create Model
                  </>
                )}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Edit Thumbnail Modal */}
      <Dialog open={isEditModalOpen} onOpenChange={setIsEditModalOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Edit className="w-5 h-5 text-primary" />
              Edit Thumbnail
            </DialogTitle>
            <DialogDescription>
              Enter a prompt to update and regenerate this thumbnail
            </DialogDescription>
          </DialogHeader>
          
          <div className="space-y-6">
            {editingThumbnail && (
              <div className="space-y-2">
                <Label>Current Thumbnail</Label>
                <div className="relative aspect-video bg-muted rounded-lg overflow-hidden">
                  {(() => {
                    const urlField = editingThumbnail.file_location || editingThumbnail.image_url || editingThumbnail.result_image_url;
                    if (!urlField) return null;
                    const imgSrc = urlField.startsWith('http://') || urlField.startsWith('https://') || urlField.startsWith('blob:')
                      ? urlField
                      : `${API_BASE_URL}${urlField.startsWith('/') ? '' : '/'}${urlField}`;
                    return (
                    <img
                      src={imgSrc}
                      alt={editingThumbnail.description || 'Thumbnail'}
                      className="w-full h-full object-cover"
                    />
                    );
                  })() || (
                    <div className="w-full h-full flex items-center justify-center">
                      <ImageIcon className="w-12 h-12 text-muted-foreground" />
                    </div>
                  )}
                </div>
              </div>
            )}

            <div className="space-y-2">
              <Label htmlFor="edit-prompt">What would you like to change?</Label>
              <Textarea
                id="edit-prompt"
                value={editPrompt}
                onChange={(e) => setEditPrompt(e.target.value)}
                placeholder="What would you like to change"
                className="min-h-[100px]"
              />
            </div>

            <div className="flex justify-end gap-2">
              <Button
                variant="outline"
                onClick={() => {
                  setIsEditModalOpen(false);
                  setEditPrompt("");
                  setEditingThumbnail(null);
                }}
                disabled={isUpdatingThumbnail}
              >
                Cancel
              </Button>
              <Button
                onClick={updateThumbnail}
                disabled={isUpdatingThumbnail || !editPrompt.trim()}
                className="gap-2"
              >
                {isUpdatingThumbnail ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Updating...
                  </>
                ) : (
                  <>
                    <Edit className="w-4 h-4" />
                    Update
                  </>
                )}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Copy Thumbnail Preview Modal */}
      <Dialog open={isCopyModalOpen} onOpenChange={setIsCopyModalOpen}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>
              Copy Thumbnail Preview
            </DialogTitle>
            <DialogDescription>
              View and download your generated copy thumbnail
            </DialogDescription>
          </DialogHeader>

          {selectedCopyThumbnail && (
            <div className="space-y-4">
              {/* Side-by-Side Comparison */}
              {selectedCopyThumbnail.status === 'completed' && (selectedCopyThumbnail.image_url || selectedCopyThumbnail.result_image_url || selectedCopyThumbnail.result_image_path || selectedCopyThumbnail.result_image_paths?.[0]) && (
                <div className="grid grid-cols-2 gap-4">
                  {/* Inspiration Image (Input) */}
                  <div className="space-y-2">
                    <p className="text-sm font-medium text-center text-muted-foreground">Inspiration Image</p>
                    <div className="rounded-lg overflow-hidden bg-muted flex items-center justify-center">
                      {selectedCopyThumbnail.base_image_path || selectedCopyThumbnail.base_image_url || selectedCopyThumbnail.source_image_url ? (
                        <img
                          src={(() => {
                            const path = selectedCopyThumbnail.base_image_path || selectedCopyThumbnail.base_image_url || selectedCopyThumbnail.source_image_url || '';
                            if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('blob:')) {
                              return path;
                            }
                            return `${API_BASE_URL}${path.startsWith('/') ? '' : '/storage/'}${path}`;
                          })()}
                          alt="Inspiration image"
                          className="max-w-full max-h-[60vh] w-auto object-contain"
                        />
                      ) : (
                        <p className="text-sm text-muted-foreground">No inspiration image available</p>
                      )}
                    </div>
                  </div>

                  {/* Result Image (Output) */}
                  <div className="space-y-2">
                    <p className="text-sm font-medium text-center text-muted-foreground">Generated Image (Result)</p>
                    <div className="rounded-lg overflow-hidden bg-muted flex items-center justify-center">
                      <img
                        src={(() => {
                          // Prefer image_url/result_image_url (already has /storage/ prefix)
                          const urlField = selectedCopyThumbnail.image_url || selectedCopyThumbnail.result_image_url;
                          if (urlField) {
                            if (urlField.startsWith('http://') || urlField.startsWith('https://') || urlField.startsWith('blob:')) {
                              return urlField;
                            }
                            return `${API_BASE_URL}${urlField.startsWith('/') ? '' : '/'}${urlField}`;
                          }
                          const path = selectedCopyThumbnail.result_image_path || selectedCopyThumbnail.result_image_paths?.[0] || '';
                          if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('blob:')) {
                            return path;
                          }
                          return `${API_BASE_URL}/storage/${path}`;
                        })()}
                        alt="Generated copy thumbnail"
                        className="max-w-full max-h-[60vh] w-auto object-contain"
                      />
                    </div>
                  </div>
                </div>
              )}

              {/* Error Message */}
              {selectedCopyThumbnail.error_message && (
                <div className="p-3 bg-red-50 rounded-md text-sm text-red-600">
                  {selectedCopyThumbnail.error_message}
                </div>
              )}

              {/* Details */}
              <div className="space-y-2 text-sm">
                {selectedCopyThumbnail.quality && (
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">Quality:</span>
                    <span className="font-medium capitalize">{selectedCopyThumbnail.quality}</span>
                  </div>
                )}

                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Created:</span>
                  <span className="font-medium">
                    {selectedCopyThumbnail.created_at
                      ? new Date(selectedCopyThumbnail.created_at).toLocaleString()
                      : 'Just now'}
                  </span>
                </div>
              </div>

              {/* Actions */}
              {selectedCopyThumbnail.status === 'completed' && (
                <div className="flex gap-2 pt-4 border-t">
                  <Button
                    onClick={() => downloadCopyThumbnail(selectedCopyThumbnail.id)}
                    className="flex-1 gap-2"
                  >
                    <Download className="w-4 h-4" />
                    Download
                  </Button>
                  <Button
                    variant="outline"
                    onClick={() => setIsCopyModalOpen(false)}
                    className="flex-1"
                  >
                    Close
                  </Button>
                </div>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
};

export default Thumbnails;
