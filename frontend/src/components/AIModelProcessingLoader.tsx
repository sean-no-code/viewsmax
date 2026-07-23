import { Loader2, User } from 'lucide-react';
import { useAIModelProcessing } from '@/contexts/AIModelProcessingContext';

export const AIModelProcessingLoader = () => {
  const { processingModels, isPolling } = useAIModelProcessing();

  if (processingModels.length === 0) {
    return null;
  }

  return (
    <div className="flex items-center gap-3 px-3 py-2 bg-primary/5 border border-primary/20 rounded-lg">
      <div className="flex items-center gap-2">
        <Loader2 className="w-4 h-4 animate-spin text-primary" />
        <span className="text-sm font-medium text-primary">Processing AI Models</span>
      </div>
      
      <div className="flex items-center gap-2">
        {processingModels.map((model) => (
          <div key={model.id} className="flex items-center gap-2 px-2 py-1 bg-white/50 rounded border">
            {model.thumbnail_image ? (
              <img 
                src={model.thumbnail_image} 
                alt={model.name}
                className="w-6 h-6 rounded object-cover"
              />
            ) : (
              <div className="w-6 h-6 bg-primary/10 rounded flex items-center justify-center">
                <User className="w-3 h-3 text-primary" />
              </div>
            )}
            <span className="text-xs font-medium text-foreground">{model.name}</span>
            <div className="flex items-center gap-1">
              <Loader2 className="w-3 h-3 animate-spin text-primary" />
              <span className="text-xs text-muted-foreground">
                {model.status === 'pending' ? 'Pending' : 'Processing'}
              </span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};
