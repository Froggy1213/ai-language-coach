/**
 * GraphQL documents, in one place so a page never carries a query string.
 *
 * Selection sets mirror the SDL: `me` and `roadmap` are owner-scoped on the
 * server, and `generateRoadmap` is idempotent, so the client re-reads the
 * roadmap instead of trusting the mutation's own payload.
 */

export const ME_QUERY = /* GraphQL */ `
  query Me {
    me {
      id
      name
      targetLanguage
      currentLevel
    }
  }
`

export const LOGIN_MUTATION = /* GraphQL */ `
  mutation Login($email: String!, $password: String!) {
    login(email: $email, password: $password) {
      id
      name
    }
  }
`

export const REGISTER_MUTATION = /* GraphQL */ `
  mutation Register($name: String!, $email: String!, $password: String!, $targetLanguage: String!) {
    register(name: $name, email: $email, password: $password, targetLanguage: $targetLanguage) {
      id
      name
    }
  }
`

export const LOGOUT_MUTATION = /* GraphQL */ `
  mutation Logout {
    logout
  }
`

export const ROADMAP_QUERY = /* GraphQL */ `
  query Roadmap {
    roadmap {
      id
      title
      status
      lessonCards {
        id
        orderIndex
        status
        practicePrompt
        cheatSheet {
          rule
          formula
          examples
          pitfalls
        }
        grammarPoint {
          id
          code
          title
          category
        }
      }
    }
  }
`

export const GENERATE_ROADMAP_MUTATION = /* GraphQL */ `
  mutation GenerateRoadmap {
    generateRoadmap {
      id
      title
    }
  }
`

export const CREATE_ASSESSMENT_UPLOAD_URL_MUTATION = /* GraphQL */ `
  mutation CreateAssessmentUploadUrl($contentType: String!) {
    createAssessmentUploadUrl(contentType: $contentType) {
      uploadUrl
      fileUrl
      fields {
        name
        value
      }
    }
  }
`

export const SUBMIT_ASSESSMENT_MUTATION = /* GraphQL */ `
  mutation SubmitAssessment($audioUrl: String!) {
    submitAssessment(audioUrl: $audioUrl) {
      id
      status
      cefrLevel
    }
  }
`

/**
 * Sent over HTTP rather than a socket: Lighthouse picks the Pusher channel from
 * `extensions.lighthouse_subscriptions.channel` and routes the event to the
 * socket named in the `X-Socket-ID` header, so the caller supplies both.
 */
export const ASSESSMENT_READY_SUBSCRIPTION = /* GraphQL */ `
  subscription AssessmentReady($userId: ID!) {
    assessmentReady(userId: $userId) {
      id
      status
      cefrLevel
    }
  }
`
