import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { Model } from '@/lib/api-service';
import { viewsMaxApi } from '@/lib/api-service';
import { toast } from 'sonner';

interface AIModelProcessingContextType {
  processingModels: Model[];
  isPolling: boolean;
  addProcessingModel: (model: Model) => void;
  removeProcessingModel: (modelId: string | number) => void;
}

const AIModelProcessingContext = createContext<AIModelProcessingContextType | undefined>(undefined);

export const useAIModelProcessing = () => {
  const context = useContext(AIModelProcessingContext);
  if (!context) {
    throw new Error('useAIModelProcessing must be used within an AIModelProcessingProvider');
  }
  return context;
};

interface AIModelProcessingProviderProps {
  children: ReactNode;
}

export const AIModelProcessingProvider = ({ children }: AIModelProcessingProviderProps) => {
  const [processingModels, setProcessingModels] = useState<Model[]>(() => {
    // Restore processing models from localStorage on initialization
    try {
      const saved = localStorage.getItem('ai-processing-models');
      return saved ? JSON.parse(saved) : [];
    } catch {
      return [];
    }
  });
  const [isPolling, setIsPolling] = useState(false);
  const [previousModels, setPreviousModels] = useState<Model[]>([]);

  const addProcessingModel = (model: Model) => {
    setProcessingModels(prev => [...prev.filter(m => m.id !== model.id), model]);
  };

  const removeProcessingModel = (modelId: string | number) => {
    setProcessingModels(prev => prev.filter(m => m.id !== modelId));
  };

  // Persist processing models to localStorage whenever they change
  useEffect(() => {
    try {
      localStorage.setItem('ai-processing-models', JSON.stringify(processingModels));
    } catch (error) {
      console.error('Failed to save processing models to localStorage:', error);
    }
  }, [processingModels]);

  const loadModels = async () => {
    try {
      const result = await viewsMaxApi.getModels();
      
      if (result.success && result.data) {
        // Handle both direct array and nested data structure
        const modelsData = Array.isArray(result.data) 
          ? result.data 
          : Array.isArray((result.data as { data?: Model[] }).data) 
            ? (result.data as { data: Model[] }).data 
            : [];
        
        // Check for status changes and show notifications
        modelsData.forEach(newModel => {
          const previousModel = previousModels.find(p => p.id === newModel.id);
          if (previousModel && previousModel.status !== newModel.status) {
            if (newModel.status === 'completed') {
              toast.success(`Model "${newModel.name}" is ready!`);
              removeProcessingModel(newModel.id);
            } else if (newModel.status === 'failed') {
              toast.error(`Model "${newModel.name}" creation failed.`);
              removeProcessingModel(newModel.id);
            }
          }
        });
        
        // Update processing models with latest data and validate against server state
        setProcessingModels(prev => {
          const updatedProcessingModels = prev.map(processingModel => {
            const updatedModel = modelsData.find(m => m.id === processingModel.id);
            return updatedModel || processingModel;
          }).filter(model => {
            // Keep models that are pending, processing, or don't have a status yet
            // Also check if the model still exists on the server
            const serverModel = modelsData.find(m => m.id === model.id);
            return serverModel && (!model.status || model.status === 'pending' || model.status === 'processing');
          });
          
          // Also add any processing models from server that aren't already in our list
          const serverProcessingModels = modelsData.filter(model => 
            (!model.status || model.status === 'pending' || model.status === 'processing') &&
            !updatedProcessingModels.some(pm => pm.id === model.id)
          );
          
          return [...updatedProcessingModels, ...serverProcessingModels];
        });
        
        setPreviousModels(modelsData);
      }
    } catch (error) {
      console.error('Error loading models:', error);
    }
  };

  // Polling effect
  useEffect(() => {
    const pollInterval = 5000; // Poll every 5 seconds
    let intervalId: NodeJS.Timeout;

    if (processingModels.length > 0) {
      console.log('Starting polling for models:', processingModels.map(m => m.id));
      setIsPolling(true);
      intervalId = setInterval(() => {
        loadModels();
      }, pollInterval);
    } else {
      setIsPolling(false);
    }

    return () => {
      if (intervalId) {
        clearInterval(intervalId);
        setIsPolling(false);
      }
    };
  }, [processingModels.length]);

  // Initial load
  useEffect(() => {
    loadModels();
  }, []);

  return (
    <AIModelProcessingContext.Provider value={{
      processingModels,
      isPolling,
      addProcessingModel,
      removeProcessingModel
    }}>
      {children}
    </AIModelProcessingContext.Provider>
  );
};
