import { useUserCredits } from '@/contexts/UserCreditsContext';
import { ApiResponse } from '@/lib/api-service';

/**
 * Hook to handle API responses and automatically update user credits
 */
export const useApiWithCredits = () => {
  const { updateCredits } = useUserCredits();

  const handleApiResponse = <T,>(response: ApiResponse<T>): ApiResponse<T> => {
    // Extract user_credits from response if present
    if (response.user_credits !== undefined) {
      updateCredits(response.user_credits);
    }
    return response;
  };

  return { handleApiResponse };
};







