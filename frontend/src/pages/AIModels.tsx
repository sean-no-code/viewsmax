import { useState, useEffect, useCallback, useRef } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Bot, Upload, Loader2, Trash2, Eye, X, User, Plus } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi, Model } from "@/lib/api-service";
import { useAIModelProcessing } from "@/contexts/AIModelProcessingContext";

const AIModels = () => {
  const [models, setModels] = useState<Model[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isCreatingModel, setIsCreatingModel] = useState(false);
  const [newModelName, setNewModelName] = useState("");
  const [modelImages, setModelImages] = useState<File[]>([]);
  const [isModelModalOpen, setIsModelModalOpen] = useState(false);
  const [selectedModel, setSelectedModel] = useState<Model | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isPolling, setIsPolling] = useState(false);
  
  // New form fields
  const [age, setAge] = useState<number | undefined>(undefined);
  const [typeId, setTypeId] = useState<string>("");
  const [ethnicityId, setEthnicityId] = useState<string>("");
  const [baldShavedHead, setBaldShavedHead] = useState<boolean>(false);
  const [types, setTypes] = useState<Array<{ id: string | number; name: string }>>([]);
  const [ethnicities, setEthnicities] = useState<Array<{ id: string | number; name: string }>>([]);
  const [isLoadingTypes, setIsLoadingTypes] = useState(false);
  const [isLoadingEthnicities, setIsLoadingEthnicities] = useState(false);

  // AI Model Processing Context
  const { addProcessingModel } = useAIModelProcessing();
  const previousModelsRef = useRef<Model[]>([]);

  const loadModels = useCallback(async (showLoading = true) => {
    try {
      if (showLoading) {
        setIsLoading(true);
      }
      const result = await viewsMaxApi.getModels();
      
      if (result.success && result.data) {
        // Handle both direct array and nested data structure
        const modelsData = Array.isArray(result.data) 
          ? result.data 
          : Array.isArray((result.data as { data?: Model[] }).data) 
            ? (result.data as { data: Model[] }).data 
            : [];
        
        // Check for status changes and show notifications
        const previousModels = previousModelsRef.current;
        modelsData.forEach(newModel => {
          const previousModel = previousModels.find(p => p.id === newModel.id);
          if (previousModel && previousModel.status !== newModel.status) {
            if (newModel.status === 'completed') {
              toast.success(`Model "${newModel.name}" is ready!`);
            } else if (newModel.status === 'failed') {
              toast.error(`Model "${newModel.name}" creation failed.`);
            }
          }
        });
        
        setModels(modelsData);
        previousModelsRef.current = modelsData;
      } else {
        console.error('Failed to load models:', result.error);
        setModels([]); // Set empty array as fallback
      }
    } catch (error) {
      console.error('Error loading models:', error);
      setModels([]); // Set empty array as fallback
    } finally {
      if (showLoading) {
        setIsLoading(false);
      }
    }
  }, []); // Remove models dependency to prevent infinite loop

  useEffect(() => {
    loadModels();
  }, [loadModels]);

  // Load types and ethnicities when modal opens
  useEffect(() => {
    if (isModelModalOpen) {
      loadTypes();
      loadEthnicities();
    }
  }, [isModelModalOpen]);

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

  // Polling effect for model status updates
  useEffect(() => {
    const pollInterval = 5000; // Poll every 5 seconds
    let intervalId: NodeJS.Timeout;

    const startPolling = () => {
      // Check if there are any models that need polling
      const modelsNeedingPolling = models.filter(model => 
        model.status === 'pending' || model.status === 'processing'
      );

      if (modelsNeedingPolling.length > 0) {
        console.log('Starting polling for models:', modelsNeedingPolling.map(m => m.id));
        setIsPolling(true);
        intervalId = setInterval(() => {
          loadModels(false); // Reload all models to get updated statuses (no loading state)
        }, pollInterval);
      } else {
        setIsPolling(false);
      }
    };

    startPolling();

    return () => {
      if (intervalId) {
        clearInterval(intervalId);
        setIsPolling(false);
      }
    };
  }, [models, loadModels]); // Include loadModels since it's now stable

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

  const openModelPreviewModal = (model: Model) => {
    setSelectedModel(model);
    setIsModalOpen(true);
  };

  const deleteModel = async (id: string | number) => {
    try {
      const result = await viewsMaxApi.deleteModel(id);
      
      if (result.success) {
        setModels(prev => prev.filter(model => model.id !== id));
        toast.success("Model deleted successfully!");
      } else {
        throw new Error(result.error || 'Failed to delete model');
      }
    } catch (error) {
      console.error('Error deleting model:', error);
      toast.error("Failed to delete model");
    }
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'completed':
        return 'text-green-600 bg-green-100';
      case 'pending':
      case 'processing':
        return 'text-yellow-600 bg-yellow-100';
      case 'failed':
        return 'text-red-600 bg-red-100';
      default:
        return 'text-gray-600 bg-gray-100';
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
      case 'completed':
        return 'Ready';
      case 'pending':
        return 'Pending';
      case 'processing':
        return 'Processing';
      case 'failed':
        return 'Failed';
      default:
        return 'Unknown';
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-6 max-w-6xl">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">Loading AI models...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-6xl">
      {/* Create New Model */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Bot className="w-5 h-5 text-primary" />
              Create New AI Model
            </div>
            {isPolling && (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="w-4 h-4 animate-spin" />
                <span>Checking status...</span>
              </div>
            )}
          </CardTitle>
          <CardDescription>
            Upload images to create a custom AI model for generating thumbnails
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex justify-end">
            <Button
              onClick={openModelModal}
              className="gap-2"
            >
              <Plus className="w-4 h-4" />
              Create New Model
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Models Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {models.map((model) => (
          <Card key={model.id} className="overflow-hidden">
            <div className="aspect-square bg-muted flex items-center justify-center relative">
              {model.status === 'failed' ? (
                <div className="flex flex-col items-center justify-center space-y-2">
                  <div className="w-8 h-8 rounded-full bg-destructive/10 flex items-center justify-center">
                    <span className="text-destructive text-lg">!</span>
                  </div>
                  <p className="text-sm text-destructive">Creation failed</p>
                </div>
              ) : model.status === 'completed' && model.thumbnail_image ? (
                <img
                  src={model.thumbnail_image}
                  alt={model.name}
                  className="w-full h-full object-cover"
                />
              ) : (
                <div className="flex flex-col items-center justify-center space-y-2">
                  <Loader2 className="w-8 h-8 animate-spin text-primary" />
                  <p className="text-sm text-muted-foreground">
                    {model.status === 'pending' ? 'Creating...' : 
                     model.status === 'processing' ? 'Processing...' : 
                     'Initializing...'}
                  </p>
                </div>
              )}
            </div>
            <CardContent className="p-4">
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <h3 className="font-semibold text-sm line-clamp-1">
                    {model.name}
                  </h3>
                  <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(model.status || 'unknown')}`}>
                    {getStatusText(model.status || 'unknown')}
                  </span>
                </div>
                <div className="flex items-center justify-between text-xs text-muted-foreground">
                  <span>
                    {new Date(model.created_at).toLocaleDateString()}
                  </span>
                  <div className="flex gap-1">
                    {model.status === 'completed' && (
                      <>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-6 w-6 p-0"
                          onClick={() => openModelPreviewModal(model)}
                        >
                          <Eye className="w-3 h-3" />
                        </Button>
                      </>
                    )}
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-6 w-6 p-0 text-destructive hover:text-destructive"
                      onClick={() => deleteModel(model.id)}
                    >
                      <Trash2 className="w-3 h-3" />
                    </Button>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {models.length === 0 && (
        <Card className="text-center py-12">
          <CardContent>
            <Bot className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
            <h3 className="text-lg font-semibold mb-2">No AI models yet</h3>
            <p className="text-muted-foreground">
              Create your first AI model to get started with custom thumbnail generation
            </p>
          </CardContent>
        </Card>
      )}

      {/* Model Preview Modal */}
      <Dialog open={isModalOpen} onOpenChange={setIsModalOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center justify-between">
              <span>Model Preview</span>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setIsModalOpen(false)}
                className="h-6 w-6 p-0"
              >
                <X className="w-4 h-4" />
              </Button>
            </DialogTitle>
            <DialogDescription>
              View details and status of your AI model
            </DialogDescription>
          </DialogHeader>
          
          {selectedModel && (
            <div className="space-y-6">
              {/* Model Image */}
              <div className="flex justify-center">
                <div className="relative max-w-full">
                  {selectedModel.thumbnail_image ? (
                    <img
                      src={selectedModel.thumbnail_image}
                      alt={selectedModel.name}
                      className="max-w-full max-h-[40vh] object-contain rounded-lg border"
                    />
                  ) : (
                    <div className="w-64 h-64 bg-muted rounded-lg flex items-center justify-center">
                      <User className="w-16 h-16 text-muted-foreground" />
                    </div>
                  )}
                </div>
              </div>
              
              {/* Model Details */}
              <div className="space-y-4">
                <div>
                  <Label className="text-sm font-medium text-muted-foreground">Model Name</Label>
                  <p className="text-sm mt-1">{selectedModel.name}</p>
                </div>
                
                <div>
                  <Label className="text-sm font-medium text-muted-foreground">Status</Label>
                  <div className="mt-1">
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(selectedModel.status || 'unknown')}`}>
                      {getStatusText(selectedModel.status || 'unknown')}
                    </span>
                  </div>
                </div>
                
                {selectedModel.file_upload_count && (
                  <div>
                    <Label className="text-sm font-medium text-muted-foreground">Images Uploaded</Label>
                    <p className="text-sm mt-1">{selectedModel.file_upload_count} images</p>
                  </div>
                )}
                
                <div className="flex items-center justify-between text-xs text-muted-foreground">
                  <span>Created: {new Date(selectedModel.created_at).toLocaleDateString()}</span>
                  <span>Updated: {new Date(selectedModel.updated_at).toLocaleDateString()}</span>
                </div>
              </div>
            </div>
          )}
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
              <Label>Upload Images *</Label>
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
                      <div key={`${file.name}-${file.size}-${file.lastModified}-${index}`} className="relative">
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
    </div>
  );
};

export default AIModels;
